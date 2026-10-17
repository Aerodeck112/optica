<?php

namespace App\Models;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'lock_version' => 1,
        'reschedule_count' => 0,
        'notifications_disabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'source' => AppointmentSource::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'block_starts_at' => 'datetime',
            'block_ends_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'notifications_disabled' => 'boolean',
            'lock_version' => 'integer',
            'reschedule_count' => 'integer',
        ];
    }

    /** Appointments that currently occupy time (expired holds excluded). */
    public function scopeBlocking(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now = ($now ?? now())->utc();

        return $query->whereIn('status', AppointmentStatus::blockingValues())
            ->where(fn (Builder $q) => $q->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>', $now));
    }

    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        // The database stores UTC; never let a local-time Carbon leak into a comparison.
        return $query->where('block_starts_at', '<', $to->utc())->where('block_ends_at', '>', $from->utc());
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

    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AppointmentEvent::class);
    }

    public function visit(): HasOne
    {
        return $this->hasOne(Visit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
