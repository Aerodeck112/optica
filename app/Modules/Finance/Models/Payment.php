<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public const METHODS = [
        'cash' => 'Numerar',
        'card_pos' => 'Card (POS)',
        'bank_transfer' => 'Transfer bancar',
        'online' => 'Online',
        'voucher' => 'Voucher',
    ];

    public const STATUSES = [
        'pending' => 'În așteptare',
        'succeeded' => 'Reușită',
        'failed' => 'Eșuată',
        'cancelled' => 'Anulată',
        'refunded' => 'Rambursată',
        'partially_refunded' => 'Rambursată parțial',
    ];

    protected $table = 'payments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function netMinor(): int
    {
        return $this->amount_minor - $this->refunded_minor;
    }
}
