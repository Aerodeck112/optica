<?php

namespace App\Modules\Display\Models;

use App\Modules\Booking\Models\Resource;
use App\Modules\Display\Services\DisplayVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un monitor din arenă. Dispozitivul (PC-ul) se asociază cu un cod de unică folosință și primește o credențială proprie,
 * păstrată doar ca hash. Credențiala dă acces exclusiv la datele publice ale acestui ecran.
 */
class DisplayScreen extends Model
{
    public const TYPES = ['live_arena' => 'Live Arena (sesiunea în desfășurare)', 'hall_of_legends' => 'Hall of Legends (clasament de fidelitate)'];

    public const ONLINE_SECONDS = 90;

    public const COMMANDS = [
        'live' => 'Afișează acum sesiunea live',
        'leaderboard' => 'Afișează clasamentul',
        'asset' => 'Afișează materialul selectat',
        'pause_ads' => 'Pauză reclame',
    ];

    /** Setările implicite („revenire la valorile implicite”). */
    public const DEFAULTS = [
        'title' => null,
        'subtitle' => null,
        'top_n' => 10,
        'page_seconds' => 12,
        'period' => 'all',
        'period_from' => null,
        'period_to' => null,
        'show_clock' => true,
        'animation' => 'full',
        'volume' => 0,
        'intro' => true,
        'live_ads' => true,
        'live_ad_seconds' => 8,
        'live_quiet_minutes' => 5,
        'live_uninterrupted' => false,
        'qr_text' => 'Rezervă-ți aventura',
        'qr_url' => null,
        'offline_after_seconds' => 120,
        'poll_seconds' => 8,
    ];

    protected $table = 'display_screens';

    protected $guarded = ['id'];

    protected $hidden = ['device_token_hash'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'override' => 'array',
            'telemetry' => 'array',
            'paired_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'last_data_at' => 'immutable_datetime',
            'reload_requested_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => DisplayVersion::bump());
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(DisplayPlaylist::class, 'display_playlist_id');
    }

    /** @return array<string, mixed> setările efective (implicite + cele salvate) */
    public function config(): array
    {
        $config = array_merge(self::DEFAULTS, array_intersect_key((array) $this->settings, self::DEFAULTS));
        $config['title'] = filled($config['title']) ? $config['title'] : ($this->type === 'live_arena' ? 'Live Arena' : 'Hall of Legends');
        $config['subtitle'] = filled($config['subtitle']) ? $config['subtitle']
            : ($this->type === 'live_arena' ? 'Aventura în desfășurare' : 'Jucătorii cu cele mai multe aventuri finalizate');

        return $config;
    }

    public function isPaired(): bool
    {
        return $this->device_token_hash !== null && $this->revoked_at === null;
    }

    public function isOnline(): bool
    {
        return $this->isPaired() && $this->last_seen_at && $this->last_seen_at->greaterThan(CarbonImmutable::now()->subSeconds(self::ONLINE_SECONDS));
    }

    /** Comanda rapidă activă (cele expirate nu mai contează: playlistul revine singur la automat). */
    public function activeOverride(): ?array
    {
        $override = (array) $this->override;
        if (empty($override['command']) || empty($override['expires_at'])) {
            return null;
        }

        return CarbonImmutable::parse($override['expires_at'])->isFuture() ? $override : null;
    }

    public function publicPath(): string
    {
        return '/display/'.$this->slug;
    }
}
