<?php

namespace App\Modules\Arena\Services;

use App\Modules\Arena\Models\SessionParticipant;
use App\Modules\Core\Settings\SettingsRepository;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Hall of Legends: clasament de fidelitate (nu de performanță în joc).
 *  - o participare activă la o sesiune finalizată = o sesiune pentru jucător (perechea sesiune–jucător este unică);
 *  - nu se numără sesiunile anulate sau de test, neprezentările, participanții scoși și participările excluse prin corecție;
 *  - apar nominal doar jucătorii cu nickname aprobat și acord de afișare;
 *  - egalitate = aceeași poziție (1, 1, 3); ordinea secundară stabilă este nickname-ul normalizat.
 */
class LegendsService
{
    public const DEFAULT_LEVELS = "1 = Recrut\n5 = Aventurier\n10 = Veteran\n25 = Legendă";

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return array<int, array{rank:int, player_id:int, nickname:string, avatar_key:string, sessions:int, level:?string}>
     */
    public function ranking(?CarbonImmutable $from = null, ?CarbonImmutable $to = null, int $limit = 50): array
    {
        $rows = $this->eligible()
            ->when($from, fn ($q) => $q->where('gs.ended_at', '>=', $from->utc()))
            ->when($to, fn ($q) => $q->where('gs.ended_at', '<', $to->utc()))
            ->groupBy('p.id', 'p.nickname', 'p.nickname_normalized', 'p.avatar_key')
            ->selectRaw('p.id as player_id, p.nickname, p.nickname_normalized, p.avatar_key, count(distinct gs.id) as sessions')
            ->orderByDesc('sessions')->orderBy('p.nickname_normalized')->orderBy('p.id')
            ->limit($limit)
            ->get();

        $allTime = $from || $to ? $this->allTimeCounts($rows->pluck('player_id')->all()) : $rows->pluck('sessions', 'player_id')->all();
        $out = [];
        $position = 0;
        $rank = 0;
        $previous = null;
        foreach ($rows as $row) {
            $position++;
            if ($previous !== (int) $row->sessions) {
                $rank = $position;
                $previous = (int) $row->sessions;
            }
            $out[] = [
                'rank' => $rank,
                'player_id' => (int) $row->player_id,
                'nickname' => (string) $row->nickname,
                'avatar_key' => (string) $row->avatar_key,
                'sessions' => (int) $row->sessions,
                'level' => $this->levelFor((int) ($allTime[$row->player_id] ?? $row->sessions)),
            ];
        }

        return $out;
    }

    /** @return array<int, int> [player_id => sesiuni eligibile din toate timpurile] */
    public function allTimeCounts(array $playerIds): array
    {
        if (! $playerIds) {
            return [];
        }

        return $this->eligible(false)->whereIn('p.id', $playerIds)->groupBy('p.id')
            ->selectRaw('p.id as player_id, count(distinct gs.id) as sessions')->pluck('sessions', 'player_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** @return array<int, array{min:int, name:string}> nivelurile configurate, crescător după prag */
    public function levels(): array
    {
        $text = (string) ($this->settings->get('display.legend_levels') ?: self::DEFAULT_LEVELS);
        $levels = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match('/^\s*(\d{1,4})\s*[=:\-]\s*(.{1,24})\s*$/u', $line, $m)) {
                $levels[] = ['min' => (int) $m[1], 'name' => trim($m[2])];
            }
        }
        usort($levels, fn ($a, $b) => $a['min'] <=> $b['min']);

        return $levels;
    }

    public function levelFor(int $sessions): ?string
    {
        $name = null;
        foreach ($this->levels() as $level) {
            if ($sessions >= $level['min']) {
                $name = $level['name'];
            }
        }

        return $name;
    }

    /**
     * Intervalul și eticheta perioadei afișate.
     *
     * @return array{from:?CarbonImmutable, to:?CarbonImmutable, label:string}
     */
    public function period(string $period, ?string $customFrom = null, ?string $customTo = null): array
    {
        $now = LocalTime::now();
        $months = [1 => 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
        $day = fn (CarbonImmutable $d) => $d->day.' '.$months[$d->month];

        switch ($period) {
            case 'month':
                $from = $now->startOfMonth();

                return ['from' => $from, 'to' => $from->addMonth(), 'label' => ucfirst($months[$from->month]).' '.$from->year];
            case 'week':
                $from = $now->startOfWeek(CarbonImmutable::MONDAY);
                $end = $from->addDays(6);

                return ['from' => $from, 'to' => $from->addWeek(), 'label' => 'Săptămâna '.($from->month === $end->month ? $from->day : $day($from)).'–'.$day($end)];
            case 'custom':
                $from = $customFrom ? CarbonImmutable::parse($customFrom, LocalTime::zone())->startOfDay() : null;
                $to = $customTo ? CarbonImmutable::parse($customTo, LocalTime::zone())->startOfDay()->addDay() : null;
                if ($from && $to && $to > $from) {
                    $last = $to->subDay();

                    return ['from' => $from, 'to' => $to, 'label' => $day($from).($from->year !== $last->year ? ' '.$from->year : '').' – '.$day($last).' '.$last->year];
                }
                // interval incomplet: revine la clasamentul general
            default:
                return ['from' => null, 'to' => null, 'label' => 'Din toate timpurile'];
        }
    }

    private function eligible(bool $publicOnly = true)
    {
        return SessionParticipant::query()->from('session_participants as sp')
            ->join('game_sessions as gs', 'gs.id', '=', 'sp.game_session_id')
            ->join('players as p', 'p.id', '=', 'sp.player_id')
            ->where('gs.status', 'completed')->where('gs.is_test', false)
            ->where('sp.status', 'active')->where('sp.counts', true)
            ->when($publicOnly, fn ($q) => $q->where('p.nickname_status', 'approved')->whereNotNull('p.nickname')->where('p.display_consent', 'granted'));
    }
}
