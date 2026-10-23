<?php

namespace App\Modules\Booking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Arena sau o zonă a ei. Ocuparea unei resurse blochează și strămoșii (rezervare exclusivă a arenei)
 * și descendenții (zonele) pe același interval.
 */
class Resource extends Model
{
    protected $table = 'resources';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ResourceBlock::class);
    }

    /** Id-urile resurselor care intră în conflict cu aceasta: ea, strămoșii și toți descendenții. */
    public function conflictFamilyIds(): array
    {
        $ids = [$this->id];

        $parent = $this->parent;
        while ($parent) {
            $ids[] = $parent->id;
            $parent = $parent->parent;
        }

        $queue = [$this->id];
        while ($queue) {
            $children = static::query()->whereIn('parent_id', $queue)->pluck('id')->all();
            $ids = array_merge($ids, $children);
            $queue = $children;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
