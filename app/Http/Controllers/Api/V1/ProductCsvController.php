<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ImportProductsRequest;
use App\Services\Catalog\ProductCsvService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductCsvController extends Controller
{
    public function __construct(
        private readonly ProductCsvService $productCsv,
    ) {}

    public function export(): StreamedResponse
    {
        $filters = request()->only([
            'search',
            'category_id',
            'supplier_id',
            'brand_id',
            'low_stock',
        ]);

        return $this->productCsv->exportForUser(request()->user(), $filters);
    }

    public function template(): StreamedResponse
    {
        return $this->productCsv->templateResponse();
    }

    public function import(ImportProductsRequest $request): JsonResponse
    {
        $result = $this->productCsv->importForUser(
            $request->user(),
            $request->file('file'),
        );

        return response()->json($result);
    }
}
