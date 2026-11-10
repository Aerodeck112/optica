<?php

namespace App\Modules\Booking\Services;

use App\Models\User;
use App\Modules\Arena\Models\Player;
use App\Modules\Arena\Services\AvatarCatalog;
use App\Modules\Arena\Services\PlayerException;
use App\Modules\Arena\Services\PlayerService;
use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PlayerWaiver;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Crm\Services\ConsentService;
use App\Modules\Crm\Services\CustomerResolver;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Semnarea declarației de participare: se salvează versiunea exactă a textului, identitatea participantului
 * și a semnatarului, momentul și dovada (semnătura desenată, IP-ul ca amprentă, browserul).
 * Pentru un participant minor semnează părintele/reprezentantul legal. Opțiunile de marketing sunt separate și nebifate.
 */
class PlayerWaiverService
{
    public const MAX_SIGNATURE_BYTES = 300_000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CustomerResolver $customers,
        private readonly ConsentService $consents,
    ) {}

    public function document(): ?LegalDocument
    {
        return LegalDocument::current('player_waiver');
    }

    /** Minor = sub 18 ani în ziua sesiunii (sau azi, fără rezervare). */
    public function isMinor(string $birthDate, ?Booking $booking = null): bool
    {
        $on = $booking?->starts_at ? LocalTime::toLocal($booking->starts_at)->startOfDay() : LocalTime::now()->startOfDay();

        return CarbonImmutable::parse($birthDate, LocalTime::zone())->addYears(18)->greaterThan($on);
    }

    /**
     * @param  array{participant_name: string, birth_date: string, guardian_name?: ?string, guardian_relationship?: ?string, guardian_phone?: ?string, signer_name: string, email?: ?string, signature: string, marketing_email?: bool, photo_consent?: bool, locale?: string}  $data
     * @param  array{ip?: ?string, user_agent?: ?string, source?: string}  $meta
     */
    public function sign(array $data, ?Booking $booking, array $meta = []): PlayerWaiver
    {
        $document = $this->document() ?? throw new RuntimeException('Declarația de participare nu este publicată.');
        $minor = $this->isMinor($data['birth_date'], $booking);
        if ($minor && blank($data['guardian_name'] ?? null)) {
            throw new RuntimeException('Pentru un participant minor semnează părintele sau reprezentantul legal.');
        }

        return DB::transaction(function () use ($data, $booking, $meta, $document, $minor) {
            return $this->store($data, $booking, $meta, $document, $minor);
        });
    }

    private function store(array $data, ?Booking $booking, array $meta, LegalDocument $document, bool $minor): PlayerWaiver
    {
        $waiver = PlayerWaiver::query()->create([
            'uuid' => (string) Str::uuid(),
            'booking_id' => $booking?->id,
            'legal_document_id' => $document->id,
            'document_version' => $document->version,
            'document_hash' => $document->content_hash ?: $document->computeHash(),
            'locale' => $data['locale'] ?? 'ro',
            'participant_name' => trim($data['participant_name']),
            'participant_birth_date' => $data['birth_date'],
            'is_minor' => $minor,
            'guardian_name' => $minor ? trim((string) $data['guardian_name']) : null,
            'guardian_relationship' => $minor ? (trim((string) ($data['guardian_relationship'] ?? '')) ?: null) : null,
            'guardian_phone' => $minor ? (trim((string) ($data['guardian_phone'] ?? '')) ?: null) : null,
            'signer_name' => trim($data['signer_name']),
            'signer_role' => $minor ? 'guardian' : 'self',
            'email' => filled($data['email'] ?? null) ? trim((string) $data['email']) : null,
            'signature' => $data['signature'],
            'marketing_email' => (bool) ($data['marketing_email'] ?? false) && filled($data['email'] ?? null),
            // acordul de imagine pentru minori se obține separat (documentul, secțiunea 7)
            'photo_consent' => ! $minor && (bool) ($data['photo_consent'] ?? false),
            'source' => $meta['source'] ?? 'online',
            'ip_hash' => isset($meta['ip']) ? hash('sha256', $meta['ip'].config('app.key')) : null,
            'user_agent' => isset($meta['user_agent']) ? mb_substr((string) $meta['user_agent'], 0, 255) : null,
            'signed_at' => now(),
        ]);

        if ($waiver->marketing_email) {
            $customer = $this->customers->resolve(['name' => $waiver->signer_name, 'email' => $waiver->email, 'locale' => $waiver->locale], 'waiver');
            $this->consents->grant($customer, 'email', 'waiver', [
                'text' => __('site.waiver.marketing', [], $waiver->locale),
                'form' => 'player_waiver',
                'ip_hash' => $waiver->ip_hash,
                'at' => now()->toIso8601String(),
            ]);
        }
        $this->linkPlayer($waiver, $data, $booking, $minor);
        $this->audit->as('system', null)->log('waiver.signed', $waiver, ['booking' => $booking?->code, 'minor' => $minor, 'version' => $document->version]);

        return $waiver;
    }

    /**
     * Profilul de jucător din declarație: același om (nume + data nașterii) primește același profil la fiecare vizită,
     * deci istoricul nu se pierde. Nickname-ul introdus online se verifică la recepție; acordul de afișare pe ecrane
     * se înregistrează separat (pentru minori, în numele adultului care semnează).
     */
    private function linkPlayer(PlayerWaiver $waiver, array $data, ?Booking $booking, bool $minor): void
    {
        $players = app(PlayerService::class);
        $hash = PlayerService::identityHash($waiver->participant_name, (string) $data['birth_date']);
        $player = Player::query()->where('identity_hash', $hash)->first() ?? $players->create([
            'private_name' => $waiver->participant_name,
            'identity_hash' => $hash,
            'is_minor' => $minor,
            'customer_id' => $booking?->customer_id,
            'avatar_key' => $data['avatar_key'] ?? null,
        ], 'online');
        $player->forceFill(['is_minor' => $minor])->save();
        if (filled($data['nickname'] ?? null) && ! $player->hasNickname()) {
            try {
                $players->changeNickname($player, $data['nickname'], 'online');
            } catch (PlayerException) {
                // validat deja în formular; o coliziune simultană lasă „nickname de completat” la recepție
            }
        }
        if (filled($data['avatar_key'] ?? null) && $data['avatar_key'] !== $player->avatar_key && AvatarCatalog::exists($data['avatar_key'])) {
            $players->changeAvatar($player, $data['avatar_key']);
        }
        $consent = ! empty($data['screen_display']);
        $players->setDisplayConsent($player, $consent ? 'granted' : 'refused', 'waiver', $minor ? $waiver->guardian_name : null);
        $waiver->forceFill(['player_id' => $player->id, 'screen_display' => $consent])->save();
    }

    public function verify(PlayerWaiver $waiver, User $user): void
    {
        $waiver->forceFill(['verified_at' => now(), 'verified_by' => $user->id])->save();
        $this->audit->as('user', $user->id)->log('waiver.verified', $waiver);
    }

    /** Numărul declarațiilor pentru o rezervare, comparat cu numărul de participanți. */
    public function progress(Booking $booking): array
    {
        $signed = $booking->waivers()->count();

        return ['signed' => $signed, 'expected' => (int) $booking->participants, 'complete' => $signed >= (int) $booking->participants];
    }
}
