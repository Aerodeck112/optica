<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningHour extends Model
{
    protected $table = 'opening_hours';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
            'weekday' => 'integer',
        ];
    }
}
