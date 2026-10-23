<?php

namespace App\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Segment extends Model
{
    protected $table = 'segments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'counted_at' => 'immutable_datetime',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
