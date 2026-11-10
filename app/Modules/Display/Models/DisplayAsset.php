<?php

namespace App\Modules\Display\Models;

use App\Modules\Cms\Models\Media;
use App\Modules\Display\Services\DisplayVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Material din biblioteca promoțională a ecranelor (imagine sau video din biblioteca media existentă). */
class DisplayAsset extends Model
{
    public const KINDS = ['image' => 'Imagine', 'video' => 'Videoclip'];

    public const FITS = ['cover' => 'Umple ecranul (decupare)', 'contain' => 'Întreg, pe fundal estompat (fără deformare)'];

    public const SCREENS = ['all' => 'Ambele ecrane', 'live_arena' => 'Doar Live Arena', 'hall_of_legends' => 'Doar Hall of Legends'];

    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    public const VIDEO_MIMES = ['video/mp4', 'video/webm'];

    protected $table = 'display_assets';

    protected $guarded = ['id'];

    protected $attributes = ['fit' => 'cover', 'weight' => 5, 'duration_seconds' => 8, 'screens' => 'all', 'is_active' => true];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => DisplayVersion::bump());
        static::deleted(fn () => DisplayVersion::bump());
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function isLiveAt(CarbonImmutable $now): bool
    {
        return $this->is_active
            && (! $this->starts_at || $this->starts_at->lessThanOrEqualTo($now))
            && (! $this->ends_at || $this->ends_at->greaterThan($now))
            && $this->media && $this->media->isPublishable();
    }

    public function appliesTo(string $screenType): bool
    {
        return $this->screens === 'all' || $this->screens === $screenType;
    }
}
