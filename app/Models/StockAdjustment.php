<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockAdjustment extends Model
{
    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'product_id',
        'product_variant_id',
        'quantity_delta',
        'reason',
        'unit_cost',
        'expiration_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'expiration_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockAdjustment $adjustment): void {
            if (empty($adjustment->uuid)) {
                $adjustment->uuid = (string) Str::uuid();
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
