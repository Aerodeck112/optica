<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    public const FORMATS = ['xlsx', 'csv', 'pdf'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'finished_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
