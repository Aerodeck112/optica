<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotFoundLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
