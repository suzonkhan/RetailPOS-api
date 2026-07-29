<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockMovement extends Model
{
    public const TYPE_SALE = 'sale';

    public const TYPE_RETURN = 'return';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'product_id',
        'product_variant_id',
        'type',
        'quantity_delta',
        'quantity_after',
        'reference_type',
        'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:3',
            'quantity_after' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockMovement $movement): void {
            if (empty($movement->uuid)) {
                $movement->uuid = (string) Str::uuid();
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
}
