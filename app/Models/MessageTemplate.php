<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplate extends Model
{
    public const PROVIDER_STATUSES = ['not_required', 'draft', 'submitted', 'approved', 'rejected'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ascii_only' => 'boolean', 'is_active' => 'boolean'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** WhatsApp templates must be approved by the provider before use (WAP-01, TRD-05). */
    public function isUsable(): bool
    {
        return $this->is_active && ($this->channel !== 'whatsapp' || ($this->provider_status === 'approved' && $this->provider_template));
    }
}
