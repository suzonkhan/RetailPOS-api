<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubscriptionInvoice extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const INTENT_CREATE_BRANCH = 'create_branch';

    public const INTENT_RENEW = 'renew';

    public const INTENT_UPGRADE = 'upgrade';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'plan_id',
        'coupon_id',
        'intent',
        'branch_meta',
        'billing_cycle',
        'subtotal',
        'discount_amount',
        'total_amount',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'branch_meta' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function bkashPayments(): HasMany
    {
        return $this->hasMany(BkashPayment::class);
    }

    public function latestBkashPayment(): HasOne
    {
        return $this->hasOne(BkashPayment::class)->latestOfMany();
    }
}
