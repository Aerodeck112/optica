<?php

namespace App\Modules\Arena\Services;

use App\Modules\Arena\Contracts\ResultsProvider;
use App\Modules\Arena\Models\GameSession;
use App\Modules\Arena\Models\Player;
use App\Modules\Arena\Models\SessionParticipant;
use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\Resource;
use App\Modules\Catalog\Models\Game;
use App\Modules\Display\Services\DisplayVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ciclul de viață al unei sesiuni de joc: programată → pregătire → în desfășurare ⇄ pauză → finalizată (sau anulată).
 * Fiecare tranziție și fiecare corecție se trec în jurnalul de audit.
 */
class GameSessionService
{
    public function __construct(
        private readonly PlayerService $players,
        private readonly AuditLogger $audit,
    ) {}

    /** Sesiunea pentru o rezervare: o deschide pe cea existentă sau o creează din datele rezervării. */
    public function forBooking(Booking $booking, ?int $userId = null): GameSession
    {
        $existing = GameSession::query()->where('booking_id', $booking->id)->whereNotIn('status', ['completed', 'cancelled'])->latest('id')->first();
        if ($existing) {
            return $existing;
        }
        $resourceId = $booking->occupancies()->where('is_active', true)->value('resource_id')
            ?? Resource::query()->where('type', 'arena')->whereNull('parent_id')->orderBy('id')->value('id');
        if (! $resourceId) {
            throw new PlayerException('Nu există nicio arenă configurată.', 'resource_id');
        }
        $package = $booking->package;

        return $this->create([
            'booking_id' => $booking->id,
            'resource_id' => $resourceId,
            'game_id' => $booking->game_id,
            'scheduled_at' => $booking->starts_at,
            'planned_minutes' => (int) ($package?->play_minutes ?: $package?->slot_minutes ?: 60),
            'status' => $booking->status === 'checked_in' ? 'checkin' : 'scheduled',
        ], $userId);
    }

    public function create(array $data, ?int $userId = null): GameSession
    {
        $game = isset($data['game_id']) ? Game::query()->find($data['game_id']) : null;
        $session = GameSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'booking_id' => $data['booking_id'] ?? null,
            'resource_id' => $data['resource_id'],
            'game_id' => $game?->id,
            'status' => in_array($data['status'] ?? 'scheduled', GameSession::OPEN, true) ? ($data['status'] ?? 'scheduled') : 'scheduled',
            'is_test' => (bool) ($data['is_test'] ?? false),
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'planned_minutes' => max(5, min(240, (int) ($data['planned_minutes'] ?? 60))),
            'result_type' => $data['result_type'] ?? ($game?->result_type ?: 'none'),
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
        $this->actor($userId)->log('game_session.created', $session, ['booking_id' => $session->booking_id, 'resource_id' => $session->resource_id]);

        return $session;
    }

    /** Asociază un jucător existent. După finalizare, doar cu motiv (corecție). */
    public function addParticipant(GameSession $session, Player $player, ?int $userId = null, ?string $reason = null): SessionParticipant
    {
        if ($session->status === 'cancelled') {
            throw new PlayerException('Sesiunea este anulată.', 'player_id');
        }
        if ($session->status === 'completed' && ! filled($reason)) {
            throw new PlayerException('Sesiunea este finalizată: adăugarea unui participant este o corecție și cere un motiv.', 'reason');
        }
        if (SessionParticipant::query()->where('game_session_id', $session->id)->where('player_id', $player->id)->exists()) {
            throw new PlayerException('Jucătorul este deja în această sesiune.', 'player_id');
        }

        $participant = DB::transaction(function () use ($session, $player, $reason) {
            GameSession::query()->lockForUpdate()->find($session->id);
            $slot = (int) SessionParticipant::query()->where('game_session_id', $session->id)->max('slot') + 1;

            return SessionParticipant::query()->create([
                'game_session_id' => $session->id,
                'player_id' => $player->id,
                'slot' => $slot,
                'status' => 'registered',
                'correction_note' => $reason ? mb_substr($reason, 0, 255) : null,
            ]);
        });
        $this->actor($userId)->log('game_session.participant_added', $session, ['player_id' => $player->id, 'slot' => $participant->slot, 'reason' => $reason]);
        if ($session->isRunning() || $session->status === 'completed') {
            $this->activate($participant, $userId);
        }
        DisplayVersion::bump();

        return $participant->refresh();
    }

    /** Activarea în sesiune cere nickname completat; recepția care activează confirmă și nickname-urile „de verificat”. */
    public function activate(SessionParticipant $participant, ?int $userId = null): SessionParticipant
    {
        $player = $participant->player;
        if (! $player->hasNickname()) {
            throw new PlayerException("Locul {$participant->slot}: completează nickname-ul jucătorului înainte de activare.", 'nickname');
        }
        if ($player->nickname_status === 'review') {
            $this->players->approveNickname($player, $userId);
        }
        $participant->forceFill(['status' => 'active', 'activated_at' => $participant->activated_at ?? now()])->save();
        DisplayVersion::bump();

        return $participant;
    }

    public function start(GameSession $session, ?int $userId = null): GameSession
    {
        $session = DB::transaction(function () use ($session, $userId) {
            $session = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, GameSession::OPEN, true)) {
                throw new PlayerException('Sesiunea poate fi pornită doar din starea „programată” sau „pregătire”.', 'status');
            }
            $busy = GameSession::query()->where('resource_id', $session->resource_id)->whereIn('status', GameSession::RUNNING)->whereKeyNot($session->id)->first();
            if ($busy) {
                throw new PlayerException('Pe această arenă rulează deja o sesiune (#'.$busy->id.'). Finalizeaz-o întâi.', 'status');
            }
            $participants = $session->participants()->with('player')->whereIn('status', ['registered', 'active'])->get();
            if ($participants->isEmpty()) {
                throw new PlayerException('Adaugă cel puțin un participant înainte de pornire.', 'participants');
            }
            $missing = $participants->filter(fn ($p) => ! $p->player->hasNickname())->map(fn ($p) => 'locul '.$p->slot);
            if ($missing->isNotEmpty()) {
                throw new PlayerException('Nickname de completat pentru: '.$missing->implode(', ').'. Completează-l sau scoate participantul din sesiune.', 'nickname');
            }
            foreach ($participants as $participant) {
                if ($participant->status !== 'active') {
                    $this->activate($participant, $userId);
                }
            }
            $session->forceFill(['status' => 'live', 'started_at' => now(), 'started_by' => $userId, 'paused_at' => null, 'paused_seconds' => 0])->save();

            return $session;
        });
        $this->actor($userId)->log('game_session.started', $session, ['participants' => $session->participants()->where('status', 'active')->count()]);
        DisplayVersion::bump();

        return $session;
    }

    public function pause(GameSession $session, ?int $userId = null): GameSession
    {
        $this->expect($session, ['live']);
        $session->forceFill(['status' => 'paused', 'paused_at' => now()])->save();
        $this->actor($userId)->log('game_session.paused', $session);
        DisplayVersion::bump();

        return $session;
    }

    public function resume(GameSession $session, ?int $userId = null): GameSession
    {
        $this->expect($session, ['paused']);
        $paused = $session->paused_at ? max(0, now()->getTimestamp() - $session->paused_at->getTimestamp()) : 0;
        $session->forceFill(['status' => 'live', 'paused_at' => null, 'paused_seconds' => (int) $session->paused_seconds + $paused])->save();
        $this->actor($userId)->log('game_session.resumed', $session, ['paused_seconds' => $paused]);
        DisplayVersion::bump();

        return $session;
    }

    public function adjustDuration(GameSession $session, int $minutes, ?int $userId = null): GameSession
    {
        if (in_array($session->status, ['completed', 'cancelled'], true)) {
            throw new PlayerException('Durata se poate ajusta doar înainte de finalizare.', 'planned_minutes');
        }
        $minutes = max(5, min(240, $minutes));
        $from = $session->planned_minutes;
        $session->forceFill(['planned_minutes' => $minutes])->save();
        $this->actor($userId)->log('game_session.duration_changed', $session, ['from' => $from, 'to' => $minutes]);
        DisplayVersion::bump();

        return $session;
    }

    /** Finalizarea este idempotentă: o sesiune deja finalizată nu se mai numără o dată. */
    public function finish(GameSession $session, ?int $userId = null): GameSession
    {
        $session = DB::transaction(function () use ($session, $userId) {
            $session = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $session->isRunning()) {
                throw new PlayerException('Doar o sesiune în desfășurare sau în pauză poate fi finalizată.', 'status');
            }
            $paused = $session->status === 'paused' && $session->paused_at ? max(0, now()->getTimestamp() - $session->paused_at->getTimestamp()) : 0;
            $session->forceFill(['status' => 'completed', 'ended_at' => now(), 'ended_by' => $userId, 'paused_at' => null,
                'paused_seconds' => (int) $session->paused_seconds + $paused])->save();
            // cei rămași doar „înregistrați” nu au jucat
            $session->participants()->where('status', 'registered')->update(['status' => 'no_show']);

            return $session;
        });
        $this->actor($userId)->log('game_session.finished', $session, ['minutes' => intdiv($session->elapsedSeconds(), 60)]);
        DisplayVersion::bump();

        return $session;
    }

    public function cancel(GameSession $session, string $reason, ?int $userId = null): GameSession
    {
        if (in_array($session->status, ['completed', 'cancelled'], true)) {
            throw new PlayerException('Sesiunea este deja '.mb_strtolower($session->statusLabel()).'. Pentru o greșeală folosește corecția participanților.', 'status');
        }
        $session->forceFill(['status' => 'cancelled', 'ended_at' => now(), 'ended_by' => $userId, 'notes' => trim(($session->notes ? $session->notes."\n" : '').'Anulată: '.$reason)])->save();
        $this->actor($userId)->log('game_session.cancelled', $session, ['reason' => $reason]);
        DisplayVersion::bump();

        return $session;
    }

    /**
     * Corecție trasabilă a unei participări (status sau eligibilitate pentru clasament), cu motiv obligatoriu
     * după finalizarea sesiunii.
     */
    public function correctParticipant(SessionParticipant $participant, array $changes, ?string $reason, ?int $userId = null): SessionParticipant
    {
        $session = $participant->session;
        $allowed = array_intersect_key($changes, array_flip(['status', 'counts']));
        if (isset($allowed['status']) && ! array_key_exists($allowed['status'], SessionParticipant::STATUSES)) {
            throw new PlayerException('Stare necunoscută.', 'status');
        }
        if ($session->status === 'completed' && ! filled($reason)) {
            throw new PlayerException('Sesiunea este finalizată: o corecție cere un motiv.', 'reason');
        }
        if (($allowed['status'] ?? null) === 'active' && ! $participant->player->hasNickname()) {
            throw new PlayerException('Completează nickname-ul înainte de a activa participantul.', 'nickname');
        }
        $before = $participant->only(['status', 'counts']);
        $participant->forceFill($allowed + ['correction_note' => $reason ? mb_substr($reason, 0, 255) : $participant->correction_note])->save();
        $this->actor($userId)->log('game_session.participant_corrected', $session, ['participant_id' => $participant->id, 'player_id' => $participant->player_id,
            'before' => $before, 'after' => $participant->only(['status', 'counts']), 'reason' => $reason]);
        DisplayVersion::bump();

        return $participant;
    }

    /**
     * Rezultatele unei sesiuni, validate după regula jocului. Pozițiile se calculează, nu se introduc:
     * egalitățile primesc aceeași poziție. Rezultatele manuale și cele sincronizate rămân marcate distinct.
     *
     * @param  array<int, array{score?:int|string|null, time?:string|null, time_ms?:int|null, team?:string|null}>  $rows  [participant_id => rezultat]
     */
    public function recordResults(GameSession $session, array $rows, string $source = 'manual', ?int $userId = null): GameSession
    {
        $type = $session->result_type;
        if ($type === 'none') {
            throw new PlayerException('Pentru acest joc nu este configurată o regulă de clasament. Alege regula în fișa sesiunii.', 'result_type');
        }
        if (! in_array($session->status, ['live', 'paused', 'completed'], true)) {
            throw new PlayerException('Rezultatele se introduc după pornirea sesiunii.', 'status');
        }
        $participants = $session->participants()->where('status', 'active')->get()->keyBy('id');
        if ($participants->isEmpty()) {
            throw new PlayerException('Sesiunea nu are participanți activi.', 'participants');
        }

        $clean = [];
        foreach ($participants as $id => $participant) {
            $row = $rows[$id] ?? [];
            $clean[$id] = match ($type) {
                'score_high' => ['score' => $this->integer($row['score'] ?? null, "locul {$participant->slot}"), 'time_ms' => null, 'team' => null],
                'time_low' => ['score' => null, 'time_ms' => $row['time_ms'] ?? $this->milliseconds($row['time'] ?? null, "locul {$participant->slot}"), 'team' => null],
                'team_score' => ['score' => $this->integer($row['score'] ?? null, "locul {$participant->slot}"), 'time_ms' => null,
                    'team' => $this->team($row['team'] ?? null, "locul {$participant->slot}")],
            };
        }
        if ($type === 'team_score') {
            $byTeam = [];
            foreach ($clean as $row) {
                $byTeam[$row['team']][] = $row['score'];
            }
            foreach ($byTeam as $team => $scores) {
                if (count(array_unique($scores)) > 1) {
                    throw new PlayerException("Echipa „{$team}” are scoruri diferite: scorul de echipă este același pentru toți membrii.", 'score');
                }
            }
            if (count($byTeam) < 2) {
                throw new PlayerException('Pentru clasamentul pe echipe sunt necesare cel puțin două echipe.', 'team');
            }
        }

        $places = $this->places($clean, $type);
        DB::transaction(function () use ($clean, $places, $source, $session, $userId, $participants) {
            foreach ($clean as $id => $row) {
                $participants[$id]->forceFill($row + ['place' => $places[$id], 'result_source' => $source])->save();
            }
            $session->forceFill(['results_source' => $source, 'results_entered_at' => now(), 'results_entered_by' => $userId])->save();
        });
        $this->actor($userId)->log('game_session.results_recorded', $session, ['source' => $source, 'type' => $type, 'results' => $clean]);
        DisplayVersion::bump();

        return $session;
    }

    public function clearResults(GameSession $session, string $reason, ?int $userId = null): GameSession
    {
        $session->participants()->update(['score' => null, 'time_ms' => null, 'team' => null, 'place' => null, 'result_source' => null]);
        $session->forceFill(['results_source' => null, 'results_entered_at' => null, 'results_entered_by' => null])->save();
        $this->actor($userId)->log('game_session.results_cleared', $session, ['reason' => $reason]);
        DisplayVersion::bump();

        return $session;
    }

    /** Preia rezultatele din integrarea configurată (dacă există). Nu se inventează nimic când sursa nu are date. */
    public function syncResults(GameSession $session, ResultsProvider $provider): bool
    {
        $data = $provider->fetch($session);
        if (! $data) {
            return false;
        }
        $byUuid = $session->participants()->where('status', 'active')->with('player')->get()->keyBy(fn ($p) => $p->player->uuid);
        $rows = [];
        foreach ($data as $row) {
            if ($participant = $byUuid[$row['player_uuid'] ?? ''] ?? null) {
                $rows[$participant->id] = $row;
            }
        }
        $this->recordResults($session, $rows, 'sync');

        return true;
    }

    /** @return array<int, int> [participant_id => poziție]; egalitățile au aceeași poziție (1, 1, 3). */
    private function places(array $rows, string $type): array
    {
        $value = fn (array $row) => match ($type) {
            'time_low' => $row['time_ms'],
            default => -$row['score'],
        };
        uasort($rows, fn ($a, $b) => $value($a) <=> $value($b));
        $places = [];
        $position = 0;
        $previous = null;
        $place = 0;
        foreach ($rows as $id => $row) {
            $position++;
            if ($previous === null || $value($row) !== $previous) {
                $place = $position;
                $previous = $value($row);
            }
            $places[$id] = $place;
        }

        return $places;
    }

    private function integer(mixed $value, string $who): int
    {
        if (! is_numeric($value) || (string) (int) $value !== trim((string) $value) || (int) $value < 0 || (int) $value > 100000000) {
            throw new PlayerException("Scor invalid pentru {$who}: introdu un număr întreg pozitiv.", 'score');
        }

        return (int) $value;
    }

    /** „12:34.5”, „1:02:03” sau secunde („754”) → milisecunde. */
    private function milliseconds(mixed $value, string $who): int
    {
        $text = trim((string) $value);
        if (! preg_match('/^(?:(\d{1,2}):)?(?:(\d{1,3}):)?(\d{1,5})(?:[.,](\d{1,3}))?$/', $text, $m)) {
            throw new PlayerException("Timp invalid pentru {$who}: folosește formatul mm:ss sau mm:ss.ms.", 'time');
        }
        $parts = array_values(array_filter([$m[1] ?? '', $m[2] ?? ''], fn ($p) => $p !== ''));
        $seconds = (int) $m[3];
        $minutes = 0;
        $hours = 0;
        if (count($parts) === 1) {
            $minutes = (int) $parts[0];
        } elseif (count($parts) === 2) {
            [$hours, $minutes] = [(int) $parts[0], (int) $parts[1]];
        }
        if ($parts && $seconds > 59) {
            throw new PlayerException("Timp invalid pentru {$who}: secundele sunt între 0 și 59.", 'time');
        }
        $ms = (int) str_pad((string) ($m[4] ?? '0'), 3, '0');
        $total = (($hours * 60 + $minutes) * 60 + $seconds) * 1000 + $ms;
        if ($total <= 0 || $total > 6 * 3600 * 1000) {
            throw new PlayerException("Timp invalid pentru {$who}.", 'time');
        }

        return $total;
    }

    private function team(mixed $value, string $who): string
    {
        $team = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ($team === '' || mb_strlen($team) > 20 || ! preg_match('/^[\p{Latin}\p{Nd} ._\-]+$/u', $team)) {
            throw new PlayerException("Echipă invalidă pentru {$who} (1–20 litere sau cifre).", 'team');
        }

        return $team;
    }

    private function expect(GameSession $session, array $statuses): void
    {
        if (! in_array($session->status, $statuses, true)) {
            throw new PlayerException('Operația nu este posibilă din starea „'.$session->statusLabel().'”.', 'status');
        }
    }

    private function actor(?int $userId): AuditLogger
    {
        return $userId ? $this->audit->as('user', $userId) : $this->audit;
    }
}
