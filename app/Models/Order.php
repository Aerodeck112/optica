<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends Model
{
    public const STATUSES = ['new', 'lab', 'in_progress', 'quality_check', 'ready', 'delivered', 'cancelled'];

    /** Allowed next statuses (CMD-02). Delivered and cancelled are final. */
    public const TRANSITIONS = [
        'new' => ['lab', 'in_progress', 'cancelled'],
        'lab' => ['in_progress', 'quality_check', 'cancelled'],
        'in_progress' => ['lab', 'quality_check', 'cancelled'],
        'quality_check' => ['ready', 'in_progress', 'lab', 'cancelled'],
        'ready' => ['delivered', 'quality_check', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'new', 'total_cents' => 0, 'lock_version' => 1];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_cents' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderByDesc('id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['delivered', 'cancelled'], true);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Past the estimated date and not handed over (CMD-03). */
    public function scopeDelayed(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['delivered', 'cancelled'])
            ->whereDate('due_on', '<', now(config('optica.clinic_timezone'))->toDateString());
    }

    public function isDelayed(): bool
    {
        return $this->isOpen() && $this->due_on && $this->due_on->toDateString() < now(config('optica.clinic_timezone'))->toDateString();
    }
}
