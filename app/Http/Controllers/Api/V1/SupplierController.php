<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreSupplierRequest;
use App\Http\Requests\Catalog\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\Catalog\CatalogScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierController extends Controller
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $store = $this->catalogScope->resolveStore(request()->user());

        $suppliers = Supplier::query()
            ->where('store_id', $store->id)
            ->orderBy('name')
            ->get();

        return SupplierResource::collection($suppliers)->additional([
            'meta' => [
                'count' => $suppliers->count(),
            ],
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $user = $request->user();
        $store = $this->catalogScope->resolveStore($user);

        $supplier = Supplier::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'name' => $request->validated('name'),
            'phone' => $request->validated('phone'),
            'email' => $request->validated('email'),
            'address' => $request->validated('address'),
            'is_active' => $request->validated('is_active', true),
        ]);

        return SupplierResource::make($supplier)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        $this->authorizeSupplier($supplier);

        return SupplierResource::make($supplier);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $this->authorizeSupplier($supplier);

        $supplier->fill($request->validated());
        $supplier->save();

        return SupplierResource::make($supplier);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->authorizeSupplier($supplier);

        $supplier->delete();

        return response()->json([
            'message' => 'Supplier deleted successfully.',
        ]);
    }

    private function authorizeSupplier(Supplier $supplier): void
    {
        $store = request()->user()->tenant?->store;

        if ($store === null || (int) $supplier->store_id !== (int) $store->id) {
            abort(404);
        }
    }
}
