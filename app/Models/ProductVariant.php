<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'product_id',
        'sku',
        'barcode',
        'selling_price',
        'cost_price',
        'stock_quantity',
        'option_signature',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock_quantity' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant): void {
            if (empty($variant->uuid)) {
                $variant->uuid = (string) Str::uuid();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(
            VariationAttributeValue::class,
            'product_variant_options',
            'product_variant_id',
            'variation_attribute_value_id'
        )->withTimestamps();
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function resolvedSellingPrice(): float
    {
        if ($this->selling_price !== null) {
            return (float) $this->selling_price;
        }

        return (float) ($this->product?->selling_price ?? 0);
    }

    public function resolvedCostPrice(): ?float
    {
        if ($this->cost_price !== null) {
            return (float) $this->cost_price;
        }

        return $this->product?->cost_price !== null
            ? (float) $this->product->cost_price
            : null;
    }

    public function buildLabel(): string
    {
        $this->loadMissing(['options.attribute']);

        return $this->options
            ->sortBy(fn (VariationAttributeValue $v) => $v->attribute?->sort_order ?? 0)
            ->map(fn (VariationAttributeValue $v) => ($v->attribute?->name ?? '').': '.$v->value)
            ->implode(', ');
    }
}
