<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'channels' => 'array'];
    }
}
