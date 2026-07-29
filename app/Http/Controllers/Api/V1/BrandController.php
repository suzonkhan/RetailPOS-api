<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Services\Catalog\CatalogScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BrandController extends Controller
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $store = $this->catalogScope->resolveStore(request()->user());

        $brands = Brand::query()
            ->where('store_id', $store->id)
            ->orderBy('name')
            ->get();

        return BrandResource::collection($brands)->additional([
            'meta' => [
                'count' => $brands->count(),
            ],
        ]);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $user = $request->user();
        $store = $this->catalogScope->resolveStore($user);

        $brand = Brand::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'name' => $request->validated('name'),
            'is_active' => $request->validated('is_active', true),
        ]);

        return BrandResource::make($brand)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Brand $brand): BrandResource
    {
        $this->authorizeBrand($brand);

        return BrandResource::make($brand);
    }

    public function update(UpdateBrandRequest $request, Brand $brand): BrandResource
    {
        $this->authorizeBrand($brand);

        $brand->fill($request->validated());
        $brand->save();

        return BrandResource::make($brand);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->authorizeBrand($brand);

        $brand->delete();

        return response()->json([
            'message' => 'Brand deleted successfully.',
        ]);
    }

    private function authorizeBrand(Brand $brand): void
    {
        $store = request()->user()->tenant?->store;

        if ($store === null || (int) $brand->store_id !== (int) $store->id) {
            abort(404);
        }
    }
}
