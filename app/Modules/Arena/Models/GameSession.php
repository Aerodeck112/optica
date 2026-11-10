<?php

namespace App\Modules\Arena\Models;

use App\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\Resource;
use App\Modules\Catalog\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesiunea de joc efectivă. O rezervare ajunsă la ora programată nu înseamnă că jocul a început:
 * starea „în desfășurare” o setează operatorul (sau, pe viitor, o integrare verificată).
 */
class GameSession extends Model
{
    public const STATUSES = [
        'scheduled' => 'Programată',
        'checkin' => 'Pregătire / check-in',
        'live' => 'În desfășurare',
        'paused' => 'Pauză',
        'completed' => 'Finalizată',
        'cancelled' => 'Anulată',
    ];

    public const RESULT_TYPES = [
        'none' => 'Fără clasament (doar participanți)',
        'score_high' => 'Scor — câștigă scorul mai mare',
        'time_low' => 'Timp — câștigă timpul mai mic',
        'team_score' => 'Echipe — câștigă echipa cu scorul mai mare',
    ];

    public const OPEN = ['scheduled', 'checkin'];

    public const RUNNING = ['live', 'paused'];

    protected $table = 'game_sessions';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'scheduled', 'planned_minutes' => 60, 'paused_seconds' => 0, 'result_type' => 'none', 'is_test' => false];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'results_entered_at' => 'immutable_datetime',
            'is_test' => 'boolean',
            'planned_minutes' => 'integer',
            'paused_seconds' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SessionParticipant::class)->orderBy('slot');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    /** Secundele de joc efectiv (fără pauze) până la momentul dat. */
    public function elapsedSeconds(?CarbonImmutable $at = null): int
    {
        if (! $this->started_at) {
            return 0;
        }
        $end = $this->ended_at ?? ($this->status === 'paused' && $this->paused_at ? $this->paused_at : ($at ?? CarbonImmutable::now()));

        return max(0, $end->getTimestamp() - $this->started_at->getTimestamp() - (int) $this->paused_seconds);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
