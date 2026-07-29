<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SyncBatch extends Model
{
    public const DIRECTION_PUSH = 'push';

    public const DIRECTION_PULL = 'pull';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'device_id',
        'direction',
        'status',
        'accepted_count',
        'rejected_count',
        'ignored_count',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SyncBatch $batch): void {
            if (empty($batch->uuid)) {
                $batch->uuid = (string) Str::uuid();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }
}
