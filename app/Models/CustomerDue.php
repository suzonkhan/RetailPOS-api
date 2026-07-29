<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CustomerDue extends Model
{
    use SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_SETTLED = 'settled';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'customer_id',
        'sale_id',
        'sale_payment_id',
        'amount',
        'balance',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CustomerDue $due): void {
            if (empty($due->uuid)) {
                $due->uuid = (string) Str::uuid();
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function salePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class);
    }

    public function duePayments(): HasMany
    {
        return $this->hasMany(DuePayment::class);
    }
}
