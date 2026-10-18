<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    public const TYPES = ['contact', 'product_inquiry', 'booking_request', 'b2b'];

    public const STATUSES = ['new', 'contacted', 'in_progress', 'converted', 'closed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['utm' => 'array', 'due_at' => 'datetime'];
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
}
