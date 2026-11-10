<?php

namespace App\Modules\Arena\Services;

use App\Modules\Arena\Models\NicknameBlock;
use App\Modules\Arena\Models\Player;
use App\Modules\Audit\AuditLogger;
use App\Modules\Display\Services\DisplayVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Profilurile de jucător: creare (cu avatar atribuit automat), nickname moderat, avatar, preferința de afișare.
 * Toate modificările de identitate publică se trec în jurnalul de audit.
 */
class PlayerService
{
    public function __construct(
        private readonly NicknamePolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{nickname?:?string, avatar_key?:?string, customer_id?:?int, private_name?:?string, is_minor?:bool, display_consent?:?string, guardian?:?string, identity_hash?:?string}  $data
     * @param  string  $source  staff (introdus de echipă: aprobat direct) | online (introdus de jucător: se verifică la recepție)
     */
    public function create(array $data, string $source = 'staff', ?int $userId = null): Player
    {
        $nickname = filled($data['nickname'] ?? null) ? NicknamePolicy::clean($data['nickname']) : null;
        if ($nickname !== null && ($problem = $this->policy->problem($nickname))) {
            throw new PlayerException($problem);
        }

        try {
            $player = Player::query()->create([
                'uuid' => (string) Str::uuid(),
                'customer_id' => $data['customer_id'] ?? null,
                'private_name' => filled($data['private_name'] ?? null) ? trim($data['private_name']) : null,
                'identity_hash' => $data['identity_hash'] ?? null,
                'is_minor' => (bool) ($data['is_minor'] ?? false),
                'nickname' => $nickname,
                'nickname_normalized' => $nickname !== null ? NicknamePolicy::normalize($nickname) : null,
                'nickname_status' => $nickname === null ? 'missing' : ($source === 'staff' ? 'approved' : 'review'),
                'nickname_source' => $nickname === null ? null : $source,
                'nickname_reviewed_by' => $nickname !== null && $source === 'staff' ? $userId : null,
                'nickname_reviewed_at' => $nickname !== null && $source === 'staff' ? now() : null,
                'avatar_key' => AvatarCatalog::exists($data['avatar_key'] ?? null) ? $data['avatar_key'] : AvatarCatalog::assign(),
                'created_by' => $userId,
            ]);
        } catch (QueryException $e) {
            throw $this->duplicate($e);
        }
        if (array_key_exists('display_consent', $data) && $data['display_consent'] !== null) {
            $this->setDisplayConsent($player, $data['display_consent'], $source === 'staff' ? 'reception' : 'waiver', $data['guardian'] ?? null, $userId, false);
        }
        $this->actor($userId)->log('player.created', $player, ['nickname' => $nickname, 'source' => $source]);
        DisplayVersion::bump();

        return $player->refresh();
    }

    /** Schimbarea nickname-ului păstrează jucătorul (același id, același istoric). */
    public function changeNickname(Player $player, string $raw, string $source = 'staff', ?int $userId = null): Player
    {
        $nickname = NicknamePolicy::clean($raw);
        if ($problem = $this->policy->problem($nickname, $player)) {
            throw new PlayerException($problem);
        }
        $from = $player->nickname;
        try {
            $player->forceFill([
                'nickname' => $nickname,
                'nickname_normalized' => NicknamePolicy::normalize($nickname),
                'nickname_status' => $source === 'staff' ? 'approved' : 'review',
                'nickname_source' => $source,
                'nickname_reviewed_by' => $source === 'staff' ? $userId : null,
                'nickname_reviewed_at' => $source === 'staff' ? now() : null,
            ])->save();
        } catch (QueryException $e) {
            throw $this->duplicate($e);
        }
        $this->actor($userId)->log('player.nickname_changed', $player, ['from' => $from, 'to' => $nickname, 'source' => $source]);
        DisplayVersion::bump();

        return $player;
    }

    public function approveNickname(Player $player, ?int $userId = null): Player
    {
        if (! filled($player->nickname)) {
            throw new PlayerException('Jucătorul nu are încă nickname.');
        }
        if ($this->policy->isOffensive($player->nickname)) {
            throw new PlayerException('Nickname-ul se află pe lista de cuvinte nepotrivite și nu poate fi aprobat.');
        }
        $player->forceFill(['nickname_status' => 'approved', 'nickname_reviewed_by' => $userId, 'nickname_reviewed_at' => now()])->save();
        $this->actor($userId)->log('player.nickname_approved', $player, ['nickname' => $player->nickname]);
        DisplayVersion::bump();

        return $player;
    }

    /** Respinge nickname-ul: forma lui nu mai poate fi folosită, iar profilul rămâne cu „nickname de completat”. */
    public function blockNickname(Player $player, string $reason, ?int $userId = null): Player
    {
        $nickname = (string) $player->nickname;
        DB::transaction(function () use ($player, $nickname, $reason, $userId) {
            if ($nickname !== '') {
                NicknameBlock::query()->firstOrCreate(
                    ['normalized' => NicknamePolicy::normalize($nickname)],
                    ['label' => mb_substr($nickname, 0, 60), 'reason' => mb_substr($reason, 0, 190), 'created_by' => $userId],
                );
            }
            $player->forceFill(['nickname' => null, 'nickname_normalized' => null, 'nickname_status' => 'blocked', 'nickname_source' => null,
                'nickname_reviewed_by' => $userId, 'nickname_reviewed_at' => now()])->save();
        });
        $this->actor($userId)->log('player.nickname_blocked', $player, ['nickname' => $nickname, 'reason' => $reason]);
        DisplayVersion::bump();

        return $player;
    }

    public function changeAvatar(Player $player, string $key, ?int $userId = null): Player
    {
        if (! AvatarCatalog::exists($key)) {
            throw new PlayerException('Avatar necunoscut.', 'avatar_key');
        }
        $from = $player->avatar_key;
        $player->forceFill(['avatar_key' => $key])->save();
        $this->actor($userId)->log('player.avatar_changed', $player, ['from' => $from, 'to' => $key]);
        DisplayVersion::bump();

        return $player;
    }

    /**
     * Preferința de afișare pe ecranele locației (separată de acordul de marketing și de acordul foto).
     * Pentru un minor, acordul îl dă adultul responsabil, al cărui nume se păstrează (criptat).
     */
    public function setDisplayConsent(Player $player, string $consent, string $source, ?string $guardian = null, ?int $userId = null, bool $log = true): Player
    {
        if (! array_key_exists($consent, Player::CONSENTS)) {
            throw new PlayerException('Opțiune de afișare necunoscută.', 'display_consent');
        }
        if ($consent === 'granted' && $player->is_minor && ! filled($guardian)) {
            throw new PlayerException('Pentru un minor, acordul de afișare îl dă părintele sau tutorele: completează numele adultului.', 'guardian');
        }
        $from = $player->display_consent;
        $player->forceFill([
            'display_consent' => $consent,
            'display_consent_source' => $source,
            'display_consent_guardian' => $player->is_minor && filled($guardian) ? trim($guardian) : null,
            'display_consent_at' => now(),
        ])->save();
        if ($log) {
            $this->actor($userId)->log('player.display_consent', $player, ['from' => $from, 'to' => $consent, 'source' => $source]);
        }
        DisplayVersion::bump();

        return $player;
    }

    /** Amprenta (HMAC) a persoanei: nume normalizat + data nașterii. Recunoaște același jucător la o nouă declarație, fără a stoca datele în clar. */
    public static function identityHash(string $name, string $birthDate): string
    {
        $normalized = (string) preg_replace('/[^a-z]/', '', Str::lower(Str::ascii($name)));

        return hash_hmac('sha256', $normalized.'|'.substr($birthDate, 0, 10), (string) config('app.key'));
    }

    private function actor(?int $userId): AuditLogger
    {
        return $userId ? $this->audit->as('user', $userId) : $this->audit;
    }

    private function duplicate(QueryException $e): \Throwable
    {
        return str_contains($e->getMessage(), 'nickname_normalized')
            ? new PlayerException('Nickname-ul este deja folosit de alt jucător. Alege altul.')
            : $e;
    }
}
