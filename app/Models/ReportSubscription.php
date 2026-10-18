<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSubscription extends Model
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'recipients' => 'array', 'is_active' => 'boolean', 'last_sent_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
