<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'simulation' => 'array',
            'stats' => 'array',
            'issues' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
