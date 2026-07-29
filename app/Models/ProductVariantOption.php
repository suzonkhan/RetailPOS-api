<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantOption extends Model
{
    protected $fillable = [
        'product_variant_id',
        'variation_attribute_value_id',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(VariationAttributeValue::class, 'variation_attribute_value_id');
    }
}
