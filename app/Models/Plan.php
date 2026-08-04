<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'monthly_price',
        'yearly_price',
        'max_users',
        'max_stores',
        'max_categories',
        'max_products',
        'is_active',
        'is_trial_default',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_trial_default' => 'boolean',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
