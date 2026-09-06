<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreStoreUomConversionRequest;
use App\Http\Requests\Catalog\UpdateStoreUomConversionRequest;
use App\Http\Resources\StoreUomConversionResource;
use App\Models\StoreUomConversion;
use App\Services\Catalog\CatalogScopeService;
use App\Services\Catalog\UomCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoreUomConversionController extends Controller
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly UomCatalogService $uoms,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $store = $this->catalogScope->resolveStore(request()->user());
        $conversions = $this->uoms->conversionsForStore($store);

        return StoreUomConversionResource::collection($conversions)->additional([
            'meta' => [
                'count' => $conversions->count(),
            ],
        ]);
    }

    public function store(StoreStoreUomConversionRequest $request): JsonResponse
    {
        $store = $this->catalogScope->resolveStore($request->user());
        $conversion = $this->uoms->createConversion($store, $request->validated());

        return StoreUomConversionResource::make($conversion)
            ->response()
            ->setStatusCode(201);
    }

    public function show(StoreUomConversion $uomConversion): StoreUomConversionResource
    {
        $this->authorizeConversion($uomConversion);

        return StoreUomConversionResource::make(
            $uomConversion->load(['fromUom', 'toUom'])
        );
    }

    public function update(
        UpdateStoreUomConversionRequest $request,
        StoreUomConversion $uomConversion,
    ): StoreUomConversionResource {
        $this->authorizeConversion($uomConversion);

        $conversion = $this->uoms->updateConversion(
            $uomConversion,
            $request->validated()
        );

        return StoreUomConversionResource::make($conversion);
    }

    public function destroy(StoreUomConversion $uomConversion): JsonResponse
    {
        $this->authorizeConversion($uomConversion);
        $this->uoms->deleteConversion($uomConversion);

        return response()->json([
            'message' => 'Conversion deleted successfully.',
        ]);
    }

    private function authorizeConversion(StoreUomConversion $conversion): void
    {
        $store = $this->catalogScope->resolveStore(request()->user());

        if ((int) $conversion->store_id !== (int) $store->id) {
            abort(404);
        }
    }
}
