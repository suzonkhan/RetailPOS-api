<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PaymentMethod extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'name',
        'is_active',
        'sort_order',
        'requires_reference',
        'is_credit',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'requires_reference' => 'boolean',
            'is_credit' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentMethod $method): void {
            if (empty($method->uuid)) {
                $method->uuid = (string) Str::uuid();
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
}
