<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['preferred_from' => 'date', 'preferred_to' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(Specialist::class);
    }

    public function displayName(): string
    {
        return $this->patient?->fullName() ?? (string) $this->contact_name;
    }

    public function phone(): ?string
    {
        return $this->patient?->phone ?? $this->contact_phone;
    }
}
