<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranslationOverride extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_verified' => 'boolean', 'published_at' => 'datetime'];
    }
}
