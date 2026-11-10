<?php

namespace App\Modules\Display\Models;

use App\Modules\Display\Services\DisplayVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisplayPlaylist extends Model
{
    public const MODES = ['smart' => 'Aleatoriu controlat (variat, cu ponderi)', 'sequential' => 'Secvențial (în ordinea listei)'];

    protected $table = 'display_playlists';

    protected $guarded = ['id'];

    protected $attributes = ['mode' => 'smart', 'min_main_seconds' => 45, 'max_consecutive_ads' => 2, 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => DisplayVersion::bump());
        static::deleted(fn () => DisplayVersion::bump());
    }

    public function items(): HasMany
    {
        return $this->hasMany(DisplayPlaylistItem::class)->orderBy('sort')->orderBy('id');
    }
}
