<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreStockAdjustmentRequest;
use App\Http\Resources\StockAdjustmentResource;
use App\Http\Resources\StockLotResource;
use App\Services\Inventory\InventoryQueryService;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryQueryService $inventory,
        private readonly StockAdjustmentService $adjustments,
    ) {}

    public function lots(): AnonymousResourceCollection
    {
        $paginator = $this->inventory->listLotsForUser(
            request()->user(),
            request()->only(['product_id', 'expired', 'has_remaining', 'per_page', 'page'])
        );

        return StockLotResource::collection($paginator)->additional([
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function adjust(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $adjustment = $this->adjustments->createForUser(
            $request->user(),
            $request->validated()
        );

        return StockAdjustmentResource::make($adjustment)
            ->response()
            ->setStatusCode(201);
    }
}
