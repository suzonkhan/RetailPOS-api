<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StoreSetting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'default_vat_percent',
        'vat_adjust_on_sale',
    ];

    protected function casts(): array
    {
        return [
            'default_vat_percent' => 'decimal:2',
            'vat_adjust_on_sale' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StoreSetting $setting): void {
            if (empty($setting->uuid)) {
                $setting->uuid = (string) Str::uuid();
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
