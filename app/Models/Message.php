<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    public const STATUSES = ['pending', 'sending', 'accepted', 'sent', 'delivered', 'failed', 'uncertain', 'cancelled', 'skipped'];

    /** Status order: a late callback never moves a message backwards (delivered stays delivered). */
    public const RANK = ['pending' => 0, 'sending' => 1, 'uncertain' => 2, 'accepted' => 3, 'sent' => 4, 'delivered' => 5, 'failed' => 6, 'cancelled' => 6, 'skipped' => 6];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'vars' => 'array',
            'context_starts_at' => 'datetime',
            'send_after' => 'datetime',
            'next_attempt_at' => 'datetime',
            'accepted_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'account_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MessageEvent::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'sending'], true);
    }
}
