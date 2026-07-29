<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BulkUpdateProductVariantsRequest;
use App\Http\Requests\Catalog\SetupProductVariationsRequest;
use App\Http\Requests\Catalog\UpdateProductVariantRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogScopeService;
use App\Services\Catalog\ProductVariantService;
use Illuminate\Http\JsonResponse;

class ProductVariantController extends Controller
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly ProductVariantService $variantService,
    ) {}

    public function show(Product $product): JsonResponse
    {
        $this->authorizeProduct($product);

        $setup = $this->variantService->getSetup($product);

        return response()->json([
            'product' => ProductResource::make($setup['product']),
            'has_variants' => $setup['has_variants'],
            'selected_value_ids' => $setup['selected_value_ids'],
            'variants' => ProductVariantResource::collection($setup['variants']),
        ]);
    }

    public function setup(SetupProductVariationsRequest $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($product);

        $product = $this->variantService->setup($product, $request->validated());
        $setup = $this->variantService->getSetup($product);

        return response()->json([
            'product' => ProductResource::make($setup['product']),
            'has_variants' => $setup['has_variants'],
            'selected_value_ids' => $setup['selected_value_ids'],
            'variants' => ProductVariantResource::collection($setup['variants']),
        ]);
    }

    public function bulkUpdate(BulkUpdateProductVariantsRequest $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($product);

        $variants = $this->variantService->bulkUpdate($product, $request->validated('variants'));

        return response()->json([
            'variants' => ProductVariantResource::collection($variants),
            'product' => ProductResource::make($product->fresh()),
        ]);
    }

    public function update(
        UpdateProductVariantRequest $request,
        Product $product,
        ProductVariant $variant,
    ): ProductVariantResource {
        $this->authorizeProduct($product);
        $this->authorizeVariant($product, $variant);

        $updated = $this->variantService->updateVariant($product, $variant, $request->validated());

        return ProductVariantResource::make($updated);
    }

    public function destroy(Product $product, ProductVariant $variant): JsonResponse
    {
        $this->authorizeProduct($product);
        $this->authorizeVariant($product, $variant);

        $this->variantService->deleteVariant($product, $variant);

        return response()->json([
            'message' => 'Variant deactivated successfully.',
        ]);
    }

    private function authorizeProduct(Product $product): void
    {
        $store = $this->catalogScope->resolveStore(request()->user());

        if ((int) $product->store_id !== (int) $store->id) {
            abort(404);
        }
    }

    private function authorizeVariant(Product $product, ProductVariant $variant): void
    {
        if ((int) $variant->product_id !== (int) $product->id) {
            abort(404);
        }
    }
}
