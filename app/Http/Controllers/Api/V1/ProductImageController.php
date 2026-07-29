<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductImageRequest;
use App\Http\Resources\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductImageController extends Controller
{
    public function __construct(
        private readonly ProductImageService $productImageService,
    ) {}

    public function index(Product $product): AnonymousResourceCollection
    {
        $images = $this->productImageService->listForProduct($product);

        return ProductImageResource::collection($images)->additional([
            'meta' => [
                'count' => $images->count(),
            ],
        ]);
    }

    public function store(StoreProductImageRequest $request, Product $product): JsonResponse
    {
        $image = $this->productImageService->storeForProduct(
            $product,
            $request->file('image'),
            (int) $request->validated('sort_order', 0)
        );

        return ProductImageResource::make($image)
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Product $product, ProductImage $productImage): JsonResponse
    {
        $this->productImageService->delete($product, $productImage);

        return response()->json([
            'message' => 'Product image deleted successfully.',
        ]);
    }
}
