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
    public const TYPES = [
        'live_arena' => 'Live Arena (sesiunea în desfășurare)',
        'hall_of_legends' => 'Hall of Legends (clasament de fidelitate)',
        'lounge' => 'Lounge (camerele din sala de joc + reclame)',
    ];

    /** Camerele implicite ale ecranului Lounge: numele fluxurilor din go2rtc (pe PC-ul ecranului) = eticheta afișată. */
    public const DEFAULT_CAMERAS = "cam1 = Sala de joc 1\ncam2 = Sala de joc 2\ncam3 = Sala de joc 3\ncam4 = Sala de joc 4";

    public const ONLINE_SECONDS = 90;

    public const COMMANDS = [
        'live' => 'Afișează acum sesiunea live',
        'leaderboard' => 'Afișează clasamentul',
        'cameras' => 'Afișează camerele',
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
        'cameras_url' => 'http://127.0.0.1:1984',
        'cameras' => self::DEFAULT_CAMERAS,
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
        $config['title'] = filled($config['title']) ? $config['title'] : match ($this->type) {
            'live_arena' => 'Live Arena', 'lounge' => 'Sala de joc — live', default => 'Hall of Legends',
        };
        $config['subtitle'] = filled($config['subtitle']) ? $config['subtitle'] : match ($this->type) {
            'live_arena' => 'Aventura în desfășurare', 'lounge' => 'Urmăriți aventura în timp real', default => 'Jucătorii cu cele mai multe aventuri finalizate',
        };

        return $config;
    }

    /**
     * Camerele ecranului Lounge, din rândurile „nume_flux = Etichetă”. Fluxurile vin de la go2rtc, care rulează pe PC-ul
     * televizorului și preia RTSP-ul camerelor din rețeaua locală; serverul nu vede și nu stochează imaginile.
     *
     * @return array<int, array{stream: string, label: string}>
     */
    public function cameras(): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) ($this->config()['cameras'] ?? '')) as $line) {
            [$stream, $label] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');
            if (preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $stream)) {
                $out[] = ['stream' => $stream, 'label' => mb_substr($label ?: $stream, 0, 40)];
            }
        }

        return array_slice($out, 0, 9);
    }

    /** Adresa go2rtc: doar pe calculatorul ecranului (127.0.0.1 / localhost), niciodată o adresă publică. */
    public function camerasUrl(): ?string
    {
        $url = rtrim((string) ($this->config()['cameras_url'] ?? ''), '/');
        $host = parse_url($url, PHP_URL_HOST);
        $local = in_array($host, ['localhost', '127.0.0.1'], true);

        return $local && preg_match('#^https?://#', $url) ? $url : null;
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
