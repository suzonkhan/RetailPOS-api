<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_PENDING_DELETION = 'pending_deletion';

    public const BILLING_MONTHLY = 'monthly';

    public const BILLING_YEARLY = 'yearly';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'name',
        'phone',
        'address',
        'status',
        'trial_ends_at',
        'subscribed_at',
        'current_period_ends_at',
        'billing_cycle',
        'is_default',
        'suspended_at',
        'data_purge_scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscribed_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'is_default' => 'boolean',
            'suspended_at' => 'datetime',
            'data_purge_scheduled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(StoreSetting::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_user')
            ->withPivot('is_primary')
            ->withTimestamps();
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

        if ($this->status === self::STATUS_PENDING_DELETION) {
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

        if ($this->status === self::STATUS_PENDING_DELETION) {
            return;
        }

        if ($this->hasActiveSubscription()) {
            if ($this->current_period_ends_at->isPast()) {
                $this->update([
                    'status' => self::STATUS_EXPIRED,
                    'data_purge_scheduled_at' => now()->addDays(
                        (int) config('retail360.expired_branch_purge_days', 30)
                    ),
                ]);
            }

            return;
        }

        if ($this->isOnTrial()) {
            return;
        }

        if ($this->status === self::STATUS_TRIAL && $this->trial_ends_at?->isPast()) {
            $this->update([
                'status' => self::STATUS_EXPIRED,
                'data_purge_scheduled_at' => now()->addDays(
                    (int) config('retail360.expired_branch_purge_days', 30)
                ),
            ]);

            return;
        }

        if ($this->status !== self::STATUS_EXPIRED && ! $this->isOnTrial() && ! $this->hasActiveSubscription()) {
            $this->update([
                'status' => self::STATUS_EXPIRED,
                'data_purge_scheduled_at' => $this->data_purge_scheduled_at ?? now()->addDays(
                    (int) config('retail360.expired_branch_purge_days', 30)
                ),
            ]);
        }
    }

    public function suspend(): void
    {
        $this->update([
            'status' => self::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);
    }

    public function resume(): void
    {
        if ($this->hasActiveSubscription()) {
            $this->update([
                'status' => self::STATUS_ACTIVE,
                'suspended_at' => null,
            ]);

            return;
        }

        if ($this->trial_ends_at !== null && $this->trial_ends_at->isFuture()) {
            $this->update([
                'status' => self::STATUS_TRIAL,
                'suspended_at' => null,
            ]);

            return;
        }

        if ($this->current_period_ends_at !== null && $this->current_period_ends_at->isFuture()) {
            $this->update([
                'status' => self::STATUS_ACTIVE,
                'suspended_at' => null,
            ]);

            return;
        }

        $this->update([
            'status' => self::STATUS_EXPIRED,
            'suspended_at' => null,
        ]);
    }
}
