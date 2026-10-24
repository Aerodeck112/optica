<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $table = 'referrals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rewarded_at' => 'immutable_datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_id');
    }
}
