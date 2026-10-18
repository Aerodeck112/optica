<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelAccount extends Model
{
    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    /** Drivers implemented per channel; each one talks to a real, documented API. */
    public const DRIVERS = ['email' => ['smtp'], 'sms' => ['twilio'], 'whatsapp' => ['whatsapp_cloud']];

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_active' => 'boolean', 'last_tested_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
