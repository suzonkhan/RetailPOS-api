<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class VariationAttributeValue extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'variation_attribute_id',
        'value',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VariationAttributeValue $value): void {
            if (empty($value->uuid)) {
                $value->uuid = (string) Str::uuid();
            }
        });
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(VariationAttribute::class, 'variation_attribute_id');
    }
}
