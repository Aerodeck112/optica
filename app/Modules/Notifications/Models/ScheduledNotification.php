<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Booking\Models\Booking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledNotification extends Model
{
    protected $table = 'scheduled_notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'send_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
