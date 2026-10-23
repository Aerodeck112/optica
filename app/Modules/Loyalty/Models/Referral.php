<?php

namespace App\Modules\Loyalty\Models;

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
        return $this->belongsTo(\App\Modules\Crm\Models\Customer::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Crm\Models\Customer::class, 'referred_id');
    }
}
