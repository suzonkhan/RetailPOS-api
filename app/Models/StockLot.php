<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StockLot extends Model
{
    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'product_id',
        'product_variant_id',
        'purchase_item_id',
        'received_at',
        'expiration_date',
        'unit_cost',
        'quantity_received',
        'quantity_remaining',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'expiration_date' => 'date',
            'unit_cost' => 'decimal:2',
            'quantity_received' => 'decimal:3',
            'quantity_remaining' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockLot $lot): void {
            if (empty($lot->uuid)) {
                $lot->uuid = (string) Str::uuid();
            }
        });
    }

    public function isExpired(?\DateTimeInterface $asOf = null): bool
    {
        if ($this->expiration_date === null) {
            return false;
        }

        $asOf = $asOf ?? now()->startOfDay();

        return $this->expiration_date->lt($asOf);
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

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SaleItemLotAllocation::class);
    }
}
