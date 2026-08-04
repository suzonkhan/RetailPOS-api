<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'mobile',
    'email',
    'pin_hash',
    'tenant_id',
    'default_store_id',
    'is_platform_admin',
    'failed_login_attempts',
    'locked_until',
])]
#[Hidden(['pin_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'is_platform_admin' => 'boolean',
            'pin_hash' => 'hashed',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function defaultStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'default_store_id');
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_user')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    public function getAuthPassword(): string
    {
        return $this->pin_hash;
    }

    public function primaryRole(): ?string
    {
        return $this->getRoleNames()->first();
    }
}
