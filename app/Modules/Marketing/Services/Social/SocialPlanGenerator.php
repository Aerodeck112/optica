<?php

namespace App\Modules\Marketing\Services\Social;

use App\Models\User;
use App\Modules\Catalog\Models\Game;
use App\Modules\Cms\Models\Faq;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Services\LinkResolver;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Marketing\Models\SocialPost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Propune o săptămână de postări (câte una pe zi): o temă pe zi, imagini din bibliotecă nefolosite recent
 * și texte construite doar din informațiile confirmate în panou (jocuri publicate, întrebări frecvente, adresă).
 * Postările rămân „Planificate” până când echipa le verifică.
 */
class SocialPlanGenerator
{
    /** Tema pe zi a săptămânii (1 = luni); a doua variantă se folosește în săptămânile impare. */
    private const WEEKDAY_THEMES = [
        1 => ['game', 'game'],
        2 => ['friends', 'teens'],
        3 => ['how', 'how'],
        4 => ['birthday', 'family'],
        5 => ['weekend', 'weekend'],
        6 => ['corporate', 'friends'],
        7 => ['behind', 'community'],
    ];

    /** Etichetele imaginilor potrivite fiecărei teme, în ordinea preferinței. */
    private const THEME_TAGS = [
        'game' => ['games'],
        'friends' => ['friends', 'group'],
        'teens' => ['teens'],
        'how' => ['briefing', 'product'],
        'birthday' => ['kids', 'birthday'],
        'family' => ['family', 'kids'],
        'weekend' => ['action', 'fun'],
        'corporate' => ['corporate', 'group'],
        'behind' => ['product', 'logo'],
        'community' => ['after', 'fun', 'friends'],
        'opening' => ['opening', 'logo'],
        'vouchers' => ['product', 'brand'],
    ];

    private const BASE_TAGS = '#AnotherWorldCluj #RealitateVirtuala #VRCluj #ClujNapoca';

    private const THEME_HASHTAGS = [
        'game' => '#JocVR #Multiplayer',
        'friends' => '#CuPrietenii #IesireInGrup',
        'teens' => '#Adolescenti #DistractieCuPrietenii',
        'how' => '#PrimaData #CumFunctioneaza',
        'birthday' => '#AniversareCopii #PetrecereVR',
        'family' => '#Familie #ActivitatiCopii',
        'weekend' => '#Weekend #CeFacemInCluj',
        'corporate' => '#TeamBuilding #EvenimenteCorporate',
        'behind' => '#DinCulise',
        'community' => '#Comunitate',
        'opening' => '#Deschidere #Vineri13',
        'vouchers' => '#VoucherCadou #Cadou',
    ];

    /** O imagine nu se refolosește mai devreme de atâtea zile. */
    private const REUSE_AFTER_DAYS = 28;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly LinkResolver $links,
    ) {}

    /** @return Collection<int, SocialPost> postările create (zilele care au deja o postare sunt sărite) */
    public function generateWeek(CarbonImmutable $anyDay, ?User $user = null): Collection
    {
        $monday = $anyDay->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $taken = SocialPost::query()
            ->whereBetween('publish_on', [$monday->toDateString(), $monday->addDays(6)->toDateString()])
            ->where('status', '!=', 'skipped')
            ->pluck('publish_on')->map(fn ($d) => $d->toDateString())->all();

        $created = collect();
        $usedThisRun = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $monday->addDays($i);
            if (in_array($day->toDateString(), $taken, true)) {
                continue;
            }
            $theme = self::WEEKDAY_THEMES[$day->isoWeekday()][$day->isoWeek() % 2];
            $draft = $this->draft($theme, $day, $usedThisRun);
            $usedThisRun = array_merge($usedThisRun, $draft['media_ids']);

            $created->push(SocialPost::query()->create($draft + [
                'publish_on' => $day->toDateString(),
                'publish_time' => $day->isoWeekday() >= 6 ? '11:00' : '18:00',
                'channels' => ['instagram', 'facebook'],
                'format' => count($draft['media_ids']) > 1 ? 'carousel' : 'post',
                'theme' => $theme,
                'status' => 'planned',
                'source' => 'generator',
                'created_by' => $user?->id,
            ]));
        }

        return $created;
    }

    /**
     * @param  array<int, int>  $exclude
     * @return array{title: string, caption: string, hashtags: string, media_ids: array<int, int>}
     */
    private function draft(string $theme, CarbonImmutable $day, array $exclude): array
    {
        $variant = $day->isoWeek();
        $site = preg_replace('#^https?://(www\.)?#', '', rtrim((string) config('app.url'), '/'));
        $address = $this->settings->filled('venue.address_street');
        $where = $address ? "📍 {$address}, Cluj-Napoca" : '📍 Cluj-Napoca';
        $book = "👉 Rezervări: {$site}";

        if ($theme === 'game' && ($game = $this->gameOfTheWeek($variant))) {
            $facts = array_filter([
                $game->playersLabel() ? '👥 Jucători: '.$game->playersLabel() : null,
                $game->min_age ? "✅ De la {$game->min_age} ani" : null,
            ]);
            $tagline = trim((string) $game->getTranslation('tagline', 'ro', false));
            $title = $game->getTranslation('title', 'ro');
            $media = array_values(array_diff(array_unique(array_map('intval', array_filter([$game->cover_id, ...(array) $game->gallery_ids]))), $exclude));

            return [
                'title' => "Jocul săptămânii: {$title}",
                'caption' => implode("\n\n", array_filter([
                    "🎮 Jocul săptămânii: {$title}",
                    $tagline,
                    $facts ? implode("\n", $facts) : null,
                    'Alegeți echipa, puneți căștile și vedeți cine rezistă până la final.',
                    $book,
                ])),
                'hashtags' => self::BASE_TAGS.' '.self::THEME_HASHTAGS['game'].' #'.str($title)->replace([':', ' '], '')->ascii(),
                'media_ids' => array_slice($media, 0, 3) ?: $this->pickMedia('behind', $day, $exclude, 1),
            ];
        }

        if ($theme === 'how' && ($faq = $this->faqOfTheWeek($variant))) {
            $answer = trim(strip_tags((string) $faq->getTranslation('answer', 'ro', false)));

            return [
                'title' => 'Întrebarea săptămânii',
                'caption' => implode("\n\n", array_filter([
                    '❓ '.trim((string) $faq->getTranslation('question', 'ro', false)),
                    str($answer)->limit(600)->toString(),
                    'Ai altă întrebare? Scrie-ne în mesaj și îți răspundem.',
                    $book,
                ])),
                'hashtags' => self::BASE_TAGS.' '.self::THEME_HASHTAGS['how'],
                'media_ids' => $this->pickMedia('how', $day, $exclude, 1),
            ];
        }

        $copy = $this->templates($where, $book)[$theme === 'game' ? 'behind' : $theme] ?? $this->templates($where, $book)['friends'];
        $text = $copy['texts'][$variant % count($copy['texts'])];

        return [
            'title' => $copy['title'],
            'caption' => $text,
            'hashtags' => self::BASE_TAGS.' '.(self::THEME_HASHTAGS[$theme] ?? ''),
            'media_ids' => $this->pickMedia($theme, $day, $exclude, $copy['images'] ?? 1),
        ];
    }

    /**
     * Imaginile cu etichetele temei, cele mai puțin folosite întâi; nefolosite în ultimele 28 de zile.
     *
     * @param  array<int, int>  $exclude
     * @return array<int, int>
     */
    public function pickMedia(string $theme, CarbonImmutable $day, array $exclude = [], int $count = 1): array
    {
        $wanted = self::THEME_TAGS[$theme] ?? ['friends'];
        $usage = [];
        $recent = [];
        foreach (SocialPost::query()->where('status', '!=', 'skipped')->get(['publish_on', 'media_ids']) as $post) {
            foreach ((array) $post->media_ids as $id) {
                $usage[$id] = ($usage[$id] ?? 0) + 1;
                if (abs($post->publish_on->diffInDays($day)) < self::REUSE_AFTER_DAYS) {
                    $recent[$id] = true;
                }
            }
        }

        $candidates = Media::query()->where('kind', 'image')->whereNotNull('tags')->get()
            ->filter(fn (Media $m) => $m->isPublishable() && array_intersect($wanted, (array) $m->tags) && ! in_array($m->id, $exclude, true));
        $fresh = $candidates->reject(fn (Media $m) => isset($recent[$m->id]));
        $pool = $fresh->count() >= $count ? $fresh : $candidates;

        return $pool
            ->sortBy(fn (Media $m) => [
                $usage[$m->id] ?? 0,
                // potrivirea cu prima etichetă a temei contează mai mult
                in_array($wanted[0], (array) $m->tags, true) ? 0 : 1,
                crc32($day->toDateString().'-'.$m->id),
            ])
            ->take($count)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function gameOfTheWeek(int $variant): ?Game
    {
        $games = Game::query()->visibleOnSite()->get()->filter(fn (Game $g) => in_array('ro', (array) $g->published_locales, true) || ! $g->published_locales)->values();

        return $games->isEmpty() ? null : $games[$variant % $games->count()];
    }

    private function faqOfTheWeek(int $variant): ?Faq
    {
        $faqs = Faq::query()->where('is_published', true)->orderBy('category')->orderBy('sort')->get();

        return $faqs->isEmpty() ? null : $faqs[$variant % $faqs->count()];
    }

    /** @return array<string, array{title: string, texts: array<int, string>, images?: int}> */
    private function templates(string $where, string $book): array
    {
        $birthdays = $this->links->url('page:birthdays', 'ro');
        $corporate = $this->links->url('page:corporate', 'ro');
        $vouchers = $this->links->url('page:vouchers', 'ro');

        return [
            'friends' => ['title' => 'Prieteni și grupuri', 'images' => 2, 'texts' => [
                "Unii ies la o cafea. Alții intră împreună în altă lume. 🌌\n\nLa Another World Cluj intrați împreună în joc și lucrați ca o echipă adevărată.\n\nPe cine iei cu tine data viitoare? Dă-i tag mai jos. 👇\n\n{$where}\n{$book}",
                "Provocarea săptămânii: adunați echipa, alegeți un joc și vedeți cine dă comenzile. 😄\n\nÎn realitatea virtuală, fiecare are rolul lui, iar victoria e a tuturor.\n\n{$where}\n{$book}",
                "O misiune spațială, o luptă într-un buncăr sau un turn plin de fantome. Voi alegeți lumea, noi vă ghidăm. 🚀\n\n{$where}\n{$book}",
            ]],
            'teens' => ['title' => 'Adolescenți', 'texts' => [
                "Ieșirea cu gașca, dar la alt nivel. 🎧\n\nIntrați împreună în joc, vă coordonați prin voce și vă provocați la revanșă.\n\n{$where}\n{$book}",
                "Când „hai să ieșim” devine „hai să salvăm stația spațială”. 🛰️\n\nAlegeți jocul potrivit vârstei și pregătiți-vă de echipă.\n\n{$book}",
            ]],
            'birthday' => ['title' => 'Aniversări', 'texts' => [
                "🎂 O aniversare pe care invitații o povestesc a doua zi.\n\nAlegem împreună jocurile potrivite vârstei, iar echipa noastră îi ghidează pe copii de la primul pas.\n\n".($birthdays ? "👉 Cere o ofertă: {$birthdays}" : $book),
                "Petrecerea în care sărbătoritul devine erou. 🦸\n\nSpune-ne câți copii vin și ce vârste au, iar noi propunem jocurile potrivite.\n\n".($birthdays ? "👉 Detalii: {$birthdays}" : $book),
            ]],
            'family' => ['title' => 'Familie', 'texts' => [
                "Părinți și copii în aceeași misiune. 👨‍👩‍👧‍👦\n\nÎn realitatea virtuală nu contează cine e mai mare, ci cine colaborează mai bine.\n\n{$where}\n{$book}",
                "Weekendul acesta, în loc de „ce facem?”, încercați „unde mergem: în spațiu sau într-un conac cu fantome?” 👻\n\n{$book}",
            ]],
            'weekend' => ['title' => 'Weekend', 'images' => 2, 'texts' => [
                "E vineri. Realitatea poate aștepta. 😎\n\nRezervă-ți locul pentru weekend și vino cu echipa.\n\n{$where}\n{$book}",
                "Planul de weekend: prieteni, căști VR și o lume nouă de explorat. 🌍\n\n{$book}",
                "Weekendul acesta, alege aventura. Noi pregătim căștile. 🎮\n\n{$where}\n{$book}",
            ]],
            'corporate' => ['title' => 'Companii și team building', 'texts' => [
                "Team building fără prezentări plictisitoare. 💼➡️🚀\n\nColegii intră împreună în joc, comunică, se coordonează și râd. Restul vine de la sine.\n\n".($corporate ? "👉 Cere o ofertă pentru echipă: {$corporate}" : $book),
                "Cea mai rapidă cale să vezi cine e liderul din echipă: o misiune în realitatea virtuală. 😄\n\nOrganizăm evenimente private pentru companii.\n\n".($corporate ? "👉 {$corporate}" : $book),
            ]],
            'behind' => ['title' => 'Din culise', 'texts' => [
                "Fiecare cască are numărul ei, iar fiecare jucător primește instructajul înainte de joc. 🎧\n\nNoi ne ocupăm de echipament, voi de distracție.\n\n{$book}",
                "Liniștea dinaintea misiunii. 🔦\n\nCăștile sunt pregătite. Voi sunteți?\n\n{$book}",
            ]],
            'community' => ['title' => 'Comunitate', 'texts' => [
                "Cel mai bun moment din joc? Cel de după, când toată lumea povestește ce a trăit. 😂\n\nCe joc ați încerca primul?\n\n{$book}",
                "Mulțumim că sunteți alături de noi! 💜\n\nTrimiteți-ne pozele voastre de la Another World Cluj și le distribuim.\n\n{$book}",
            ]],
            'vouchers' => ['title' => 'Vouchere cadou', 'texts' => [
                "Cauți un cadou original? Dăruiește o experiență în altă lume. 🎁\n\n".($vouchers ? "👉 Vouchere cadou: {$vouchers}" : $book),
            ]],
        ];
    }
}
