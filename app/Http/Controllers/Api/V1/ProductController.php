<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $filters = request()->only([
            'search',
            'category_id',
            'supplier_id',
            'brand_id',
            'low_stock',
            'expired',
            'per_page',
            'page',
            'include_variants',
        ]);

        $paginator = $this->productService->listForUser(request()->user(), $filters);

        return ProductResource::collection($paginator)->additional([
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->storeForUser(
            $request->user(),
            $request->validated()
        );

        return ProductResource::make($product)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        $this->authorizeProduct($product);

        return ProductResource::make($product->load(['category', 'supplier', 'brand', 'variants.options.attribute']));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $this->authorizeProduct($product);

        $product = $this->productService->update($product, $request->validated());

        return ProductResource::make($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorizeProduct($product);

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    private function authorizeProduct(Product $product): void
    {
        $store = request()->user()->tenant?->store;

        if ($store === null || (int) $product->store_id !== (int) $store->id) {
            abort(404);
        }
    }
}
