<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreUomConversion extends Model
{
    protected $fillable = [
        'tenant_id',
        'store_id',
        'from_uom_id',
        'to_uom_id',
        'factor',
    ];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:6',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function fromUom(): BelongsTo
    {
        return $this->belongsTo(StoreUom::class, 'from_uom_id');
    }

    public function toUom(): BelongsTo
    {
        return $this->belongsTo(StoreUom::class, 'to_uom_id');
    }
}
