<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreStoreUomRequest;
use App\Http\Requests\Catalog\UpdateStoreUomRequest;
use App\Http\Resources\StoreUomConversionResource;
use App\Http\Resources\StoreUomResource;
use App\Models\StoreUom;
use App\Services\Catalog\CatalogScopeService;
use App\Services\Catalog\UomCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoreUomController extends Controller
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly UomCatalogService $uoms,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $store = $this->catalogScope->resolveStore(request()->user());
        $items = $this->uoms->listForStore($store);
        $conversions = $this->uoms->conversionsForStore($store);

        return StoreUomResource::collection($items)->additional([
            'meta' => [
                'count' => $items->count(),
                'conversions' => StoreUomConversionResource::collection($conversions)->resolve(),
            ],
        ]);
    }

    public function store(StoreStoreUomRequest $request): JsonResponse
    {
        $store = $this->catalogScope->resolveStore($request->user());
        $uom = $this->uoms->createForUser($store, $request->validated());

        return StoreUomResource::make($uom)
            ->response()
            ->setStatusCode(201);
    }

    public function show(StoreUom $uom): StoreUomResource
    {
        $this->authorizeUom($uom);

        return StoreUomResource::make($uom);
    }

    public function update(UpdateStoreUomRequest $request, StoreUom $uom): StoreUomResource
    {
        $this->authorizeUom($uom);

        $uom = $this->uoms->update($uom, $request->validated());

        return StoreUomResource::make($uom);
    }

    public function destroy(StoreUom $uom): JsonResponse
    {
        $this->authorizeUom($uom);
        $this->uoms->delete($uom);

        return response()->json([
            'message' => 'Unit deleted successfully.',
        ]);
    }

    private function authorizeUom(StoreUom $uom): void
    {
        $store = $this->catalogScope->resolveStore(request()->user());

        if ((int) $uom->store_id !== (int) $store->id) {
            abort(404);
        }
    }
}
