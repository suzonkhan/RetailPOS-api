<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSaleReturnRequest;
use App\Http\Resources\SaleReturnResource;
use App\Models\Sale;
use App\Services\Sales\SaleReturnService;
use Illuminate\Http\JsonResponse;

class SaleReturnController extends Controller
{
    public function __construct(
        private readonly SaleReturnService $saleReturnService,
    ) {}

    public function store(StoreSaleReturnRequest $request, Sale $sale): JsonResponse
    {
        $saleReturn = $this->saleReturnService->createForSale(
            $request->user(),
            $sale,
            $request->validated()
        );

        return SaleReturnResource::make($saleReturn)
            ->response()
            ->setStatusCode(201);
    }
}
