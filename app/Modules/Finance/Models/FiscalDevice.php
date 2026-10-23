<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalDevice extends Model
{
    protected $table = 'fiscal_devices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'vat_mapping' => 'array',
            'payment_mapping' => 'array',
            'last_seen_at' => 'immutable_datetime',
            'last_diagnostic' => 'array',
            'is_active' => 'boolean',
            'agent_secret' => 'encrypted',
        ];
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(FiscalReceipt::class);
    }

    protected $hidden = ['agent_secret'];
}
