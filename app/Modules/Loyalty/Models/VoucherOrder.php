<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Catalog\Models\Package;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Comandă online de voucher cadou; voucherul se emite automat după confirmarea plății. */
class VoucherOrder extends Model
{
    public const STATUSES = ['pending' => 'Așteaptă plata', 'issued' => 'Emis', 'cancelled' => 'Anulată'];

    protected $table = 'voucher_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
