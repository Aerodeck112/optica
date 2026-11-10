<?php

namespace App\Modules\Display\Models;

use App\Modules\Catalog\Models\Game;
use App\Modules\Display\Services\DisplayVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O secvență din playlist. „main” = conținutul principal al ecranului (sesiunea live sau clasamentul).
 * Materialele promoționale fără un fișier anume se aleg din bibliotecă, după pondere, fără repetare consecutivă.
 */
class DisplayPlaylistItem extends Model
{
    public const TYPES = [
        'main' => 'Conținut principal (live / clasament)',
        'leaderboard' => 'Clasament general',
        'podium' => 'Podium (top 3)',
        'promo' => 'Imagine promoțională',
        'video' => 'Videoclip',
        'logo' => 'Animație logo',
        'clock' => 'Ceas și data zilei',
        'game' => 'Prezentare joc',
        'message' => 'Mesaj administrativ',
    ];

    /** Secvențe care întrerup conținutul principal și se supun regulilor de reclamă. */
    public const FILLERS = ['promo', 'video', 'logo', 'clock', 'game', 'message', 'podium', 'leaderboard'];

    protected $table = 'display_playlist_items';

    protected $guarded = ['id'];

    protected $attributes = ['weight' => 5, 'is_active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'is_active' => 'boolean', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => DisplayVersion::bump());
        static::deleted(fn () => DisplayVersion::bump());
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(DisplayAsset::class, 'display_asset_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** Activă acum: în intervalul de date, în intervalul orar și în zilele săptămânii alese (ora locației). */
    public function isScheduledAt(CarbonImmutable $local): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $date = $local->toDateString();
        if (($this->starts_on && $date < $this->starts_on->toDateString()) || ($this->ends_on && $date > $this->ends_on->toDateString())) {
            return false;
        }
        if ($this->weekdays && ! in_array($local->isoWeekday(), array_map('intval', $this->weekdays), true)) {
            return false;
        }
        $time = $local->format('H:i:s');
        if ($this->time_from && $this->time_until) {
            return $this->time_from <= $this->time_until
                ? $time >= $this->time_from && $time < $this->time_until
                : $time >= $this->time_from || $time < $this->time_until; // interval peste miezul nopții
        }
        if ($this->time_from && $time < $this->time_from) {
            return false;
        }

        return ! ($this->time_until && $time >= $this->time_until);
    }
}
