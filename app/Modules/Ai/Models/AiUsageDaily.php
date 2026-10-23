<?php

namespace App\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageDaily extends Model
{
    protected $table = 'ai_usage_daily';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
        ];
    }
}
