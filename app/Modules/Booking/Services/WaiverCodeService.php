<?php

namespace App\Modules\Booking\Services;

use App\Modules\Arena\Models\GameSession;
use App\Modules\Arena\Services\GameSessionService;
use App\Modules\Arena\Services\PlayerException;
use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PlayerWaiver;
use App\Modules\Booking\Models\WaiverCard;
use App\Modules\Booking\Models\WaiverCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Codurile de 6 cifre pentru tableta de la recepție: recepția dă fiecărui jucător un cod, jucătorul îl tastează
 * pe tabletă și semnează declarația. Codul se folosește o singură dată, expiră după câteva ore și leagă
 * declarația de rezervare (și de sesiunea de joc deschisă, dacă există).
 */
class WaiverCodeService
{
    public const MAX_PER_ISSUE = 30;

    /** Cât timp rămâne valabil un cod nefolosit (minim), în ore. */
    public const VALID_HOURS = 4;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Coduri pentru `count` jucători. Cu `useCards`, se dau întâi cardurile fizice libere (codul e cel tipărit pe card),
     * iar dacă nu ajung, restul primesc bilețele cu coduri noi.
     *
     * @return Collection<int, WaiverCode>
     */
    public function issue(?Booking $booking, int $count, ?GameSession $session = null, ?int $userId = null, bool $useCards = false): Collection
    {
        $count = max(1, min(self::MAX_PER_ISSUE, $count));
        $booking ??= $session?->booking;

        $codes = DB::transaction(function () use ($booking, $count, $session, $userId, $useCards) {
            $cards = $useCards ? $this->freeCards(lock: true)->take($count)->values() : collect();
            $codes = collect();
            for ($i = 0; $i < $count; $i++) {
                $codes->push($this->create($booking, $session, $userId, $cards->get($i)));
            }

            return $codes;
        });
        $this->audit->as('user', $userId)->log('waiver.codes_issued', $booking ?? $session, [
            'count' => $count, 'cards' => $codes->pluck('card.serial')->filter()->values()->all(), 'expires_at' => $codes->first()->expires_at->toIso8601String(),
        ]);

        return $codes;
    }

    /** Recepția scanează (sau tastează) un card anume și îl dă grupului. */
    public function assignCard(string $input, ?Booking $booking, ?GameSession $session = null, ?int $userId = null): WaiverCode
    {
        $card = $this->findCard($input) ?? throw new WaiverCodeException('Cardul nu există. Scanează codul de bare de pe spatele cardului.');
        if (! $card->is_active) {
            throw new WaiverCodeException("{$card->label()} este scos din uz.");
        }
        $booking ??= $session?->booking;

        return DB::transaction(function () use ($card, $booking, $session, $userId) {
            WaiverCard::query()->lockForUpdate()->find($card->id);
            $current = $card->currentAssignment();
            if ($current && $booking && $current->booking_id === $booking->id) {
                return $current;
            }
            if ($current) {
                throw new WaiverCodeException("{$card->label()} este deja dat altui grup".($current->booking ? " ({$current->booking->code})" : '').'. Ia-l înapoi sau dă alt card.');
            }
            $code = $this->create($booking, $session, $userId, $card);
            $this->audit->as('user', $userId)->log('waiver.card_assigned', $booking ?? $session, ['card' => $card->serial]);

            return $code;
        });
    }

    /** Cardurile de la recepție (active și nedate niciunui grup), în ordinea numerelor. @return Collection<int, WaiverCard> */
    public function freeCards(bool $lock = false): Collection
    {
        return WaiverCard::query()->where('is_active', true)
            ->whereDoesntHave('assignments', fn ($q) => $q->active())
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderBy('number')->get();
    }

    public function findCard(string $input): ?WaiverCard
    {
        $input = strtoupper(trim($input));
        if (preg_match('/^(?:AWC)?[\s\-]*0*(\d{1,3})$/', $input, $m) && str_starts_with($input, 'AWC')) {
            return WaiverCard::query()->where('number', (int) $m[1])->first();
        }
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        return strlen($digits) === 6 ? WaiverCard::query()->where('code', $digits)->first() : null;
    }

    private function create(?Booking $booking, ?GameSession $session, ?int $userId, ?WaiverCard $card = null): WaiverCode
    {
        $plain = $card?->code ?? $this->uniqueCode();
        $position = (int) WaiverCode::query()
            ->when($booking, fn ($q) => $q->where('booking_id', $booking->id), fn ($q) => $q->whereNull('booking_id'))
            ->when(! $booking && $session, fn ($q) => $q->where('game_session_id', $session->id))
            ->max('position');

        return WaiverCode::query()->create([
            'code_hash' => self::hash($plain),
            'code' => $plain,
            'booking_id' => $booking?->id,
            'game_session_id' => $session?->id,
            'waiver_card_id' => $card?->id,
            'position' => $position + 1,
            'expires_at' => $this->expiryFor($booking, $session),
            'created_by' => $userId,
        ])->setRelation('card', $card);
    }

    /** Codurile încă nefolosite ale unei rezervări (sau cele fără rezervare, emise azi). @return Collection<int, WaiverCode> */
    public function active(?Booking $booking = null, ?GameSession $session = null): Collection
    {
        return WaiverCode::query()->active()
            ->when($booking, fn ($q) => $q->where('booking_id', $booking->id))
            ->when(! $booking && $session, fn ($q) => $q->where('game_session_id', $session->id))
            ->when(! $booking && ! $session, fn ($q) => $q->whereNull('booking_id')->whereNull('game_session_id'))
            ->orderBy('position')->get();
    }

    /** Câte coduri mai trebuie date: participanți − declarații semnate − coduri încă active. */
    public function missing(Booking $booking): int
    {
        return max(0, (int) $booking->participants - $booking->waivers()->count() - $this->active($booking)->count());
    }

    /** Caută un cod activ după ce a tastat jucătorul (spațiile și alte caractere se ignoră). */
    public function find(string $input): ?WaiverCode
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        if (strlen($digits) !== 6) {
            return null;
        }

        return WaiverCode::query()->active()->where('code_hash', self::hash($digits))->first();
    }

    /**
     * Folosește codul: semnarea și marcarea codului se fac împreună, deci același cod nu poate semna de două ori
     * (nici de pe două tablete în aceeași secundă).
     *
     * @param  callable(?Booking): PlayerWaiver  $sign
     */
    public function redeem(WaiverCode $code, callable $sign): PlayerWaiver
    {
        $waiver = DB::transaction(function () use ($code, $sign) {
            $locked = WaiverCode::query()->lockForUpdate()->find($code->id);
            if (! $locked || ! $locked->isActive()) {
                throw new WaiverCodeException('Codul a fost deja folosit sau a expirat.');
            }
            $waiver = $sign($locked->booking);
            $locked->forceFill(['used_at' => now(), 'player_waiver_id' => $waiver->id])->save();

            return $waiver;
        });
        $this->joinSession($code->refresh(), $waiver);

        return $waiver;
    }

    public function revoke(WaiverCode $code, ?int $userId = null): void
    {
        if ($code->isActive()) {
            $code->forceFill(['expires_at' => now()])->save();
            $this->audit->as('user', $userId)->log('waiver.code_revoked', $code->booking ?? $code->gameSession, ['position' => $code->position]);
        }
    }

    /** Jucătorul care a semnat intră direct în sesiunea de joc deschisă a grupului (cu nickname-ul și avatarul ales). */
    private function joinSession(WaiverCode $code, PlayerWaiver $waiver): void
    {
        $session = $code->gameSession;
        if (! $session && $code->booking_id) {
            $session = GameSession::query()->where('booking_id', $code->booking_id)
                ->whereIn('status', [...GameSession::OPEN, ...GameSession::RUNNING])->latest('id')->first();
        }
        $player = $waiver->player;
        if (! $session || ! $player || ! in_array($session->status, [...GameSession::OPEN, ...GameSession::RUNNING], true)) {
            return;
        }
        try {
            app(GameSessionService::class)->addParticipant($session, $player);
        } catch (PlayerException) {
            // deja în sesiune: nimic de făcut
        }
    }

    private function expiryFor(?Booking $booking, ?GameSession $session): CarbonImmutable
    {
        $expires = CarbonImmutable::now()->addHours(self::VALID_HOURS);
        $start = $booking?->starts_at ?? $session?->scheduled_at;
        if ($start) {
            $afterStart = CarbonImmutable::parse($start)->addHours(3);
            if ($afterStart->greaterThan($expires)) {
                $expires = $afterStart;
            }
        }

        return $expires;
    }

    /** 6 cifre aleatoare, diferite de toate codurile active; fără coduri ușor de ghicit (111111, 123456). */
    private function uniqueCode(): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            if (count(array_unique(str_split($code))) < 3 || str_contains('0123456789', $code) || str_contains('9876543210', $code)) {
                continue;
            }
            if (! WaiverCard::query()->where('code', $code)->exists() && ! WaiverCode::query()->active()->where('code_hash', self::hash($code))->exists()) {
                return $code;
            }
        }
        throw new RuntimeException('Nu s-a putut genera un cod unic. Încearcă din nou.');
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', 'waiver-code|'.$code, (string) config('app.key'));
    }
}
