<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'sync_batch_id',
        'entity_type',
        'entity_key',
        'status',
        'message',
    ];

    public function syncBatch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class);
    }
}
