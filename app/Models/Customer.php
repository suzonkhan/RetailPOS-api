<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'name',
        'mobile',
        'email',
        'address',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (empty($customer->uuid)) {
                $customer->uuid = (string) Str::uuid();
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

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function dues(): HasMany
    {
        return $this->hasMany(CustomerDue::class);
    }

    public function openDues(): HasMany
    {
        return $this->hasMany(CustomerDue::class)->where('status', CustomerDue::STATUS_OPEN);
    }
}
