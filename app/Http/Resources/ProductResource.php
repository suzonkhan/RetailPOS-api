<?php

namespace App\Http\Resources;

use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Services\Catalog\UomCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'image_url' => $this->whenLoaded(
                'primaryImage',
                fn () => $this->primaryImage?->url()
            ),
            'primary_image_id' => $this->whenLoaded(
                'primaryImage',
                fn () => $this->primaryImage?->id
            ),
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'supplier_id' => $this->supplier_id,
            'brand_id' => $this->brand_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'uuid' => $this->category->uuid,
                'name' => $this->category->name,
            ]),
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'uuid' => $this->supplier->uuid,
                'name' => $this->supplier->name,
            ] : null),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'uuid' => $this->brand->uuid,
                'name' => $this->brand->name,
            ] : null),
            'selling_price' => (float) $this->selling_price,
            'cost_price' => $this->cost_price !== null ? (float) $this->cost_price : null,
            'stock_quantity' => (float) $this->stock_quantity,
            'min_stock_quantity' => $this->min_stock_quantity !== null
                ? (float) $this->min_stock_quantity
                : null,
            'expiration_date' => $this->expiration_date?->format('Y-m-d'),
            'uom' => $this->uom ?? 'pcs',
            'uom_label' => $this->resolveUomLabel($request),
            'fractional_qty' => $this->resolveFractionalQty($request),
            'is_low_stock' => $this->manage_inventory
                && $this->min_stock_quantity !== null
                && (float) $this->stock_quantity <= (float) $this->min_stock_quantity,
            'is_expired' => $this->expiration_date !== null
                && $this->expiration_date->lt(now()->startOfDay()),
            'vat_rate' => $this->vat_rate !== null ? (float) $this->vat_rate : null,
            'vat_type' => $this->vat_type,
            'is_active' => $this->is_active,
            'is_negotiable' => (bool) $this->is_negotiable,
            'ask_qty_on_add' => (bool) $this->ask_qty_on_add,
            'manage_inventory' => (bool) $this->manage_inventory,
            'has_variants' => (bool) $this->has_variants,
            'variant_count' => $this->when(
                $this->has_variants,
                fn () => $this->active_variants_count
                    ?? $this->variants()->where('is_active', true)->count()
            ),
            'total_variant_stock' => $this->when(
                $this->has_variants && $this->relationLoaded('variants'),
                fn () => (float) $this->variants->where('is_active', true)->sum('stock_quantity')
            ),
            'price_from' => $this->when(
                $this->has_variants,
                fn () => $this->relationLoaded('variants') && $this->variants->isNotEmpty()
                    ? (float) $this->variants
                        ->where('is_active', true)
                        ->map(fn ($v) => $v->resolvedSellingPrice())
                        ->min()
                    : (float) $this->selling_price
            ),
            'variants' => ProductVariantResource::collection(
                $this->whenLoaded('variants')
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }

    private function resolveUomLabel(Request $request): string
    {
        $code = $this->uom ?? 'pcs';

        return app(UomCatalogService::class)->labelForStoreId((int) $this->store_id, $code);
    }

    private function resolveFractionalQty(Request $request): bool
    {
        $code = $this->uom ?? 'pcs';

        return app(UomCatalogService::class)->isFractionalForStoreId((int) $this->store_id, $code);
    }
}
