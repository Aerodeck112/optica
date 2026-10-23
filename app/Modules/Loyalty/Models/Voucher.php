<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Catalog\Models\Package;
use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Voucher cu registru de tranzacții. balance_minor este un cache recalculat din tranzacții, sub blocare de rând.
 */
class Voucher extends Model
{
    public const STATUSES = [
        'draft' => 'Ciornă',
        'active' => 'Activ',
        'redeemed' => 'Utilizat integral',
        'expired' => 'Expirat',
        'cancelled' => 'Anulat',
    ];

    protected $table = 'vouchers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'conditions' => 'array',
            'allows_partial_use' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(VoucherTransaction::class)->orderBy('id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'buyer_customer_id');
    }

    public function isUsableOn(\DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->status === 'active'
            && ($this->valid_from === null || $this->valid_from->toDateString() <= $day)
            && ($this->valid_until === null || $this->valid_until->toDateString() >= $day);
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
