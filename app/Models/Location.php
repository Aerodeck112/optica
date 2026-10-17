<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasTranslatedContent, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'published_content' => 'array',
            'published_at' => 'datetime',
            'opening_hours' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'online_booking_enabled' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function specialists(): BelongsToMany
    {
        return $this->belongsToMany(Specialist::class)
            ->using(LocationSpecialist::class)
            ->withPivot(['id', 'is_active', 'online_enabled', 'online_paused', 'content', 'min_lead_minutes', 'max_advance_days', 'notify_on_online_booking'])
            ->withTimestamps();
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(Offering::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function name(?string $locale = null): string
    {
        return $this->contentValue('name', $locale) ?? $this->address;
    }
}
