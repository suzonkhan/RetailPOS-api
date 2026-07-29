<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'category_id',
        'supplier_id',
        'brand_id',
        'name',
        'sku',
        'barcode',
        'description',
        'selling_price',
        'cost_price',
        'stock_quantity',
        'min_stock_quantity',
        'expiration_date',
        'uom',
        'vat_rate',
        'vat_type',
        'is_active',
        'is_negotiable',
        'ask_qty_on_add',
        'manage_inventory',
        'has_variants',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock_quantity' => 'decimal:3',
            'min_stock_quantity' => 'decimal:3',
            'expiration_date' => 'date',
            'vat_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'is_negotiable' => 'boolean',
            'ask_qty_on_add' => 'boolean',
            'manage_inventory' => 'boolean',
            'has_variants' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function activeVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->where('is_active', true);
    }
}
