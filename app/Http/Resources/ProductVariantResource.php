<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->relationLoaded('product') ? $this->product : null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'selling_price' => $this->selling_price !== null ? (float) $this->selling_price : null,
            'cost_price' => $this->cost_price !== null ? (float) $this->cost_price : null,
            'resolved_selling_price' => $this->resolvedSellingPrice(),
            'resolved_cost_price' => $this->resolvedCostPrice(),
            'stock_quantity' => (float) $this->stock_quantity,
            'option_signature' => $this->option_signature,
            'variant_label' => $this->relationLoaded('options')
                ? $this->buildLabel()
                : null,
            'options' => $this->when(
                $this->relationLoaded('options'),
                fn () => $this->options->map(fn ($opt) => [
                    'id' => $opt->id,
                    'uuid' => $opt->uuid,
                    'value' => $opt->value,
                    'attribute_id' => $opt->variation_attribute_id,
                    'attribute_name' => $opt->relationLoaded('attribute')
                        ? $opt->attribute?->name
                        : null,
                ])
            ),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'base_selling_price' => $product ? (float) $product->selling_price : null,
        ];
    }
}
