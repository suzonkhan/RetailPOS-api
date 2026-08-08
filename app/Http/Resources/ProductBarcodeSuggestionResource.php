<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Suggestion payload for product-add autofill (not a tenant product record).
 *
 * @mixin array<string, mixed>
 */
class ProductBarcodeSuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this['name'],
            'barcode' => $this['barcode'],
            'sku' => $this['sku'],
            'description' => $this['description'],
            'selling_price' => $this['selling_price'],
            'category_name' => $this['category_name'],
            'brand_name' => $this['brand_name'],
            'uom' => $this['uom'],
            'vat_rate' => $this['vat_rate'],
            'vat_type' => $this['vat_type'],
            'store_name' => $this['store_name'],
            'is_own_store' => $this['is_own_store'],
            'source' => $this['source'],
        ];
    }
}
