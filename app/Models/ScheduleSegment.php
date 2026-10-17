<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleSegment extends Model
{
    public const SEGMENTS = ['morning', 'before_noon', 'afternoon', 'evening', 'extra_1', 'extra_2'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'online_enabled' => 'boolean',
            'service_ids' => 'array',
            'label' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function allowsService(int $serviceId): bool
    {
        return $this->service_ids === null || in_array($serviceId, array_map('intval', $this->service_ids), true);
    }
}
