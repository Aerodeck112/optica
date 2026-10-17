<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offering extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'online_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(Specialist::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function durationMinutes(): int
    {
        return $this->duration_minutes ?? $this->service->default_duration;
    }

    public function bufferBefore(): int
    {
        return $this->buffer_before ?? $this->service->buffer_before;
    }

    public function bufferAfter(): int
    {
        return $this->buffer_after ?? $this->service->buffer_after;
    }
}
