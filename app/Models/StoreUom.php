<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StoreUom extends Model
{
    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'code',
        'label',
        'fractional',
        'is_system',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fractional' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StoreUom $uom): void {
            if (empty($uom->uuid)) {
                $uom->uuid = (string) Str::uuid();
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

    public function conversionsFrom(): HasMany
    {
        return $this->hasMany(StoreUomConversion::class, 'from_uom_id');
    }

    public function conversionsTo(): HasMany
    {
        return $this->hasMany(StoreUomConversion::class, 'to_uom_id');
    }
}
