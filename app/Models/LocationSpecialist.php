<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class LocationSpecialist extends Pivot
{
    public $incrementing = true;

    protected $table = 'location_specialist';

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_active' => 'boolean',
            'online_enabled' => 'boolean',
            'online_paused' => 'boolean',
            'notify_on_online_booking' => 'boolean',
        ];
    }
}
