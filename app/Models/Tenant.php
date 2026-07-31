<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'slug',
        'plan_id',
        'status',
        'trial_ends_at',
        'subscribed_at',
        'current_period_ends_at',
        'billing_cycle',
        'last_order_number',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscribed_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function store(): HasOne
    {
        return $this->hasOne(Store::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->latestOfMany();
    }

    public function isOnTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    public function trialDaysRemaining(): int
    {
        if ($this->trial_ends_at === null || $this->trial_ends_at->isPast()) {
            return 0;
        }

        return (int) now()->startOfDay()->diffInDays($this->trial_ends_at->startOfDay());
    }

    public function hasActiveSubscription(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->current_period_ends_at !== null
            && $this->current_period_ends_at->isFuture();
    }

    public function requiresSubscriptionPayment(): bool
    {
        if ($this->status === self::STATUS_SUSPENDED) {
            return true;
        }

        if ($this->hasActiveSubscription()) {
            return false;
        }

        return ! $this->isOnTrial();
    }

    public function syncSubscriptionStatus(): void
    {
        if ($this->status === self::STATUS_SUSPENDED) {
            return;
        }

        if ($this->hasActiveSubscription()) {
            if ($this->current_period_ends_at->isPast()) {
                $this->update(['status' => self::STATUS_EXPIRED]);
            }

            return;
        }

        if ($this->isOnTrial()) {
            return;
        }

        if ($this->status !== self::STATUS_EXPIRED) {
            $this->update(['status' => self::STATUS_EXPIRED]);
        }
    }

    public const BILLING_MONTHLY = 'monthly';

    public const BILLING_YEARLY = 'yearly';
}
