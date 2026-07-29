<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonInterface;

class PinLockoutService
{
    public function maxAttempts(): int
    {
        return config('retail360.pin_lockout.max_attempts', 5);
    }

    public function lockoutMinutes(): int
    {
        return config('retail360.pin_lockout.lockout_minutes', 15);
    }

    public function isLocked(User $user): bool
    {
        return $user->locked_until !== null && $user->locked_until->isFuture();
    }

    public function lockedUntil(User $user): ?CarbonInterface
    {
        if (! $this->isLocked($user)) {
            return null;
        }

        return $user->locked_until;
    }

    public function recordFailedAttempt(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;

        $attributes = ['failed_login_attempts' => $attempts];

        if ($attempts >= $this->maxAttempts()) {
            $attributes['locked_until'] = now()->addMinutes($this->lockoutMinutes());
        }

        $user->update($attributes);
    }

    public function clearLockout(User $user): void
    {
        if ($user->failed_login_attempts === 0 && $user->locked_until === null) {
            return;
        }

        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);
    }
}
