<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Sale extends Model
{
    use SoftDeletes;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIALLY_RETURNED = 'partially_returned';

    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'uuid',
        'client_uuid',
        'order_number',
        'tenant_id',
        'store_id',
        'customer_id',
        'user_id',
        'updated_by',
        'subtotal',
        'vat_total',
        'discount_amount',
        'total',
        'change_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'change_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Sale $sale): void {
            if (empty($sale->uuid)) {
                $sale->uuid = (string) Str::uuid();
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function dues(): HasMany
    {
        return $this->hasMany(CustomerDue::class);
    }
}
