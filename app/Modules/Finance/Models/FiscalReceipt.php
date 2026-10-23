<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalReceipt extends Model
{
    protected $table = 'fiscal_receipts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'payments' => 'array',
            'is_simulated' => 'boolean',
            'printed_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(FiscalDevice::class, 'fiscal_device_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Booking\Models\Booking::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
