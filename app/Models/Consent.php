<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    public const PURPOSES = ['privacy_notice', 'booking_terms', 'marketing'];

    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['granted' => 'boolean', 'given_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('granted', true)->whereNull('withdrawn_at');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
