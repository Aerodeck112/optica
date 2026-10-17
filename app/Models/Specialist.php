<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Specialist extends Model
{
    use HasTranslatedContent, SoftDeletes;

    public const TYPES = ['ophthalmologist', 'optometrist', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'published_content' => 'array',
            'published_at' => 'datetime',
            'languages' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)
            ->using(LocationSpecialist::class)
            ->withPivot(['id', 'is_active', 'online_enabled', 'online_paused', 'content', 'min_lead_minutes', 'max_advance_days', 'notify_on_online_booking'])
            ->withTimestamps();
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(Offering::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}
