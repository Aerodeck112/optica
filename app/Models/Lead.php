<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Lead extends Model
{
    public const TYPES = ['contact', 'product_inquiry', 'booking_request', 'b2b'];

    public const STATUSES = ['new', 'contacted', 'in_progress', 'converted', 'closed'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'new', 'locale' => 'ro'];

    protected function casts(): array
    {
        return ['utm' => 'array', 'due_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeadEvent::class)->orderByDesc('id');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'subject');
    }
}
