<?php

namespace App\Modules\Arena\Services;

use App\Modules\Arena\Models\NicknameBlock;
use App\Modules\Arena\Models\Player;
use App\Modules\Core\Settings\SettingsRepository;
use Illuminate\Support\Str;

/**
 * Regulile nickname-ului afișat pe ecranele din arenă:
 *  - 3–18 caractere, litere latine (inclusiv diacritice), cifre, spațiu, punct, cratimă, underscore;
 *  - începe și se termină cu literă sau cifră; fără HTML, scripturi, emoji sau caractere de control (lista permisă exclude tot restul);
 *  - unic după normalizare (litere mici, fără diacritice și separatori): „Ana.Maria” = „ana maria” = „AnaMaria”;
 *  - nu conține cuvinte nepotrivite (listă de bază + listă din setări + nickname-uri respinse de echipă).
 */
class NicknamePolicy
{
    public const MIN = 3;

    public const MAX = 18;

    /** Rădăcini verificate oriunde în nickname (suficient de specifice încât să nu blocheze cuvinte nevinovate). */
    private const CONTAINS = [
        'pizd', 'fuck', 'cunt', 'nigg', 'hitler', 'curva', 'tarfa', 'jidan', 'bozgor', 'poponar', 'homalau', 'handicap',
        'cacat', 'labagiu', 'muist', 'sugipul', 'futut', 'fututi', 'pussy', 'whore', 'bitch', 'faggot', 'retard',
        'anotherworld', 'administrator', 'moderator',
    ];

    /** Cuvinte scurte verificate ca „cuvânt” separat (ex. „Pula Mare”, „PulaMare”), ca să nu blocheze „populara”. */
    private const WORDS = [
        'pula', 'pule', 'muie', 'coaie', 'futu', 'fut', 'sugi', 'curu', 'sex', 'porn', 'rape', 'nazi', 'dick', 'shit', 'fag', 'slut',
        'cock', 'anus', 'penis', 'vagin', 'tigan', 'tigani', 'admin', 'staff', 'receptie', 'aw',
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** Forma curată, așa cum se salvează: spații multiple reduse, fără spații la capete, Unicode NFC. */
    public static function clean(?string $raw): string
    {
        $value = (string) $raw;
        if (class_exists(\Normalizer::class)) {
            $value = (string) \Normalizer::normalize($value, \Normalizer::FORM_C);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Cheia de unicitate: litere mici, fără diacritice și fără separatori. */
    public static function normalize(?string $nickname): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii(self::clean($nickname))));
    }

    /**
     * Mesajul de eroare (în română) sau null dacă nickname-ul este acceptat.
     */
    public function problem(?string $raw, ?Player $ignore = null): ?string
    {
        $nickname = self::clean($raw);
        $length = mb_strlen($nickname);
        if ($length < self::MIN || $length > self::MAX) {
            return 'Nickname-ul are între '.self::MIN.' și '.self::MAX.' caractere.';
        }
        if (! preg_match('/^[\p{Latin}\p{Nd}](?:[\p{Latin}\p{Nd} ._\-]*[\p{Latin}\p{Nd}])?$/u', $nickname)) {
            return 'Folosește doar litere, cifre, spațiu, punct, cratimă sau underscore; nickname-ul începe și se termină cu literă sau cifră.';
        }
        if (preg_match('/[ ._\-]{2,}/', $nickname)) {
            return 'Nu folosi două separatoare la rând.';
        }
        $normalized = self::normalize($nickname);
        if (strlen($normalized) < 2 || ! preg_match('/[a-z]/', $normalized)) {
            return 'Nickname-ul conține cel puțin o literă.';
        }
        if ($this->isOffensive($nickname)) {
            return 'Nickname-ul nu poate fi folosit. Alege altul.';
        }
        $taken = Player::query()->where('nickname_normalized', $normalized)
            ->when($ignore?->exists, fn ($q) => $q->whereKeyNot($ignore->id))->exists();

        return $taken ? 'Nickname-ul este deja folosit de alt jucător. Alege altul.' : null;
    }

    public function isOffensive(string $nickname): bool
    {
        $normalized = self::normalize($nickname);
        if (NicknameBlock::query()->where('normalized', $normalized)->exists()) {
            return true;
        }
        $skeleton = strtr($normalized, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '8' => 'b']);
        foreach (array_merge(self::CONTAINS, $this->extraContains()) as $stem) {
            if ($stem !== '' && str_contains($skeleton, $stem)) {
                return true;
            }
        }
        foreach ($this->words($nickname) as $word) {
            if (in_array($word, self::WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /** Cuvintele nickname-ului: separate prin spații/semne, prin trecerea de la litere mici la mari („PulaMare”) și de la litere la cifre. */
    private function words(string $nickname): array
    {
        $ascii = Str::ascii(self::clean($nickname));
        $ascii = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $ascii);
        $ascii = (string) preg_replace('/([A-Za-z])(\d)|(\d)([A-Za-z])/', '$1$3 $2$4', $ascii);
        $parts = preg_split('/[^A-Za-z0-9]+/', Str::lower($ascii)) ?: [];

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /** Cuvinte suplimentare din Setări → Ecrane afișare (câte unul pe rând). */
    private function extraContains(): array
    {
        $text = (string) $this->settings->get('display.nickname_blocklist', '');

        return array_values(array_filter(array_map(fn ($line) => self::normalize($line), preg_split('/[\r\n,]+/', $text) ?: [])));
    }
}
