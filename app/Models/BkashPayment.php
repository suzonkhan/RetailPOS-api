<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BkashPayment extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'subscription_invoice_id',
        'payment_id',
        'trx_id',
        'amount',
        'status',
        'transaction_status',
        'create_response',
        'execute_response',
        'webhook_payload',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'create_response' => 'array',
            'execute_response' => 'array',
            'webhook_payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function subscriptionInvoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class);
    }
}
