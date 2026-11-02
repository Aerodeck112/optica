<?php

use App\Modules\Booking\Models\Resource;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\Faq;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\Page;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Oferta confirmată de proprietar (02.11.2026, documentele „Birthday Party Packages”, „Corporate VR Experiences”
 * și „Gift Certificates”): pachetele de aniversare și pentru companii, opțiunile suplimentare, voucherele cadou
 * și textele paginilor Aniversări, Team building și Vouchere. Prețurile sunt în lei, cu TVA inclus.
 * Pachetele existente (aceeași adresă în site) nu se suprascriu.
 */
return new class extends Migration
{
    private const PARTY_BASICS = [
        'Toată locația doar pentru voi',
        'Apă și o băutură răcoritoare pentru fiecare copil',
        'Snacksuri',
        'Masă dedicată pentru aniversare',
        'Farfurii, pahare, șervețele și accesorii de bază pentru petrecere',
        'Invitație digitală',
        'Cafea pentru părinți',
        'Timp dedicat pentru masă, tort și socializare',
    ];

    public function up(): void
    {
        // doar pe un site deja instalat; o instalare nouă își configurează singură catalogul
        if (! DB::table('pages')->where('key', 'home')->exists()) {
            return;
        }

        $arena = Resource::query()->where('type', 'arena')->whereNull('parent_id')->value('id');
        $packages = $this->packages($arena);
        $this->addons($packages);
        $this->pages();
        $this->faqs();
    }

    public function down(): void {}

    /** @return array<string, Package> după cheie */
    private function packages(?int $arena): array
    {
        $party = fn (array $specific) => array_map(fn ($text) => ['text' => $text], $specific);
        $definitions = [
            'standard' => [
                'slug' => 'aniversare-standard', 'name' => 'Standard', 'type' => 'birthday', 'price' => 199000, 'max' => 8, 'slot' => 120, 'play' => 60, 'sort' => 10,
                'summary' => 'Până la 8 copii, 2 ore. Voi aduceți tortul. Noi ne ocupăm de rest.',
                'included' => $party(['60 de minute de VR multiplayer', 'Acces la cele 2 stații PlayStation', ...array_slice(self::PARTY_BASICS, 0, 1),
                    '4 pizza', '2 meniuri crispy chicken cu cartofi prăjiți', ...array_slice(self::PARTY_BASICS, 1, 2),
                    'O șampanie pentru copii (fără alcool)', ...array_slice(self::PARTY_BASICS, 3), 'Cadou pentru sărbătorit']),
            ],
            'silver' => [
                'slug' => 'aniversare-silver', 'name' => 'Silver', 'type' => 'birthday', 'price' => 249000, 'max' => 8, 'slot' => 180, 'play' => 120, 'sort' => 11, 'featured' => true,
                'summary' => 'Cel mai ales pachet. Până la 8 copii, 3 ore, două sesiuni VR separate, cu jocuri diferite.',
                'included' => $party(['120 de minute de VR multiplayer, în două sesiuni separate (puteți încerca jocuri diferite)',
                    'Acces nelimitat la cele 2 stații PlayStation pe durata petrecerii', ...array_slice(self::PARTY_BASICS, 0, 1),
                    '4 pizza', '2 meniuri crispy chicken cu cartofi prăjiți', ...array_slice(self::PARTY_BASICS, 1, 2),
                    'O șampanie pentru copii (fără alcool)', ...array_slice(self::PARTY_BASICS, 3), 'Cadou pentru sărbătorit']),
            ],
            'gold' => [
                'slug' => 'aniversare-gold', 'name' => 'Gold', 'type' => 'birthday', 'price' => 329000, 'max' => 16, 'slot' => 180, 'play' => 60, 'sort' => 12,
                'summary' => 'Experiența completă pentru grupuri mari: până la 16 copii, 3 ore. Două echipe de până la 8 jucători: în timp ce o echipă e în arena VR, cealaltă se bucură de PlayStation, gustări și zona de petrecere, apoi echipele schimbă.',
                'included' => $party(['60 de minute de VR multiplayer pentru fiecare copil', 'Până la 8 jucători simultan în arena VR',
                    'Acces nelimitat la cele 2 stații PlayStation pe durata petrecerii', ...array_slice(self::PARTY_BASICS, 0, 1),
                    '8 pizza', '4 meniuri crispy chicken cu cartofi prăjiți', ...array_slice(self::PARTY_BASICS, 1, 2),
                    '2 șampanii pentru copii (fără alcool)', ...array_slice(self::PARTY_BASICS, 3), 'Cadou premium pentru sărbătorit']),
            ],
            'corporate_team' => [
                'slug' => 'corporate-team', 'name' => 'Corporate Team', 'type' => 'event', 'price' => 199000, 'max' => 8, 'slot' => 120, 'play' => 60, 'sort' => 20,
                'summary' => 'Experiență VR privată pentru echipe mai mici: până la 8 participanți, 2 ore.',
                'included' => $party(['60 de minute de VR multiplayer', 'Acces la cele 2 stații PlayStation', 'Toată locația doar pentru echipa voastră',
                    'VR Team Challenge: provocări multiplayer care pun accent pe comunicare, coordonare și lucru în echipă', 'Clasament și rezultate pe echipe',
                    'Apă și băuturi răcoritoare', 'Snacksuri', 'Cafea pentru participanți', 'Pizza și preparate casual', 'Fotografie de grup']),
            ],
            'corporate_challenge' => [
                'slug' => 'corporate-challenge', 'name' => 'Corporate Challenge', 'type' => 'event', 'price' => 329000, 'max' => 16, 'slot' => 180, 'play' => 60, 'sort' => 21, 'featured' => true,
                'summary' => 'Experiența completă de team building: până la 16 participanți, 3 ore. Două echipe de până la 8 alternează între arena VR și zona de PlayStation și socializare, apoi urmează finala.',
                'included' => $party(['60 de minute de VR multiplayer pentru fiecare participant', 'Acces nelimitat la cele 2 stații PlayStation pe durata evenimentului',
                    'Toată locația doar pentru echipa voastră', 'Turneu VR organizat, echipă contra echipă', 'Clasament final și premiu pentru echipa câștigătoare',
                    'Apă, băuturi răcoritoare și cafea', 'Snacksuri', 'Pizza și preparate casual', 'Fotografie de echipă', 'Timp dedicat pentru mâncare, băuturi și socializare']),
            ],
        ];

        $covers = ['standard' => 'social-copil-casca-03', 'silver' => 'social-grup-adolescenti-logo', 'gold' => 'kids-team',
            'corporate_team' => 'corporate-duo', 'corporate_challenge' => 'social-echipa-mare-logo'];

        $result = [];
        foreach ($definitions as $key => $d) {
            $existing = Package::query()->where('slug->ro', $d['slug'])->first();
            if ($existing) {
                $result[$key] = $existing;

                continue;
            }
            $result[$key] = Package::query()->create([
                'slug' => ['ro' => $d['slug']],
                'name' => ['ro' => $d['name']],
                'type' => $d['type'],
                'summary' => ['ro' => $d['summary']],
                'included' => ['ro' => $d['included']],
                'session_mode' => 'private',
                'max_participants' => $d['max'],
                'slot_minutes' => $d['slot'],
                'play_minutes' => $d['play'],
                'pricing_model' => 'flat',
                'price_minor' => $d['price'],
                'currency' => 'RON',
                'deposit_type' => 'none',
                'booking_mode' => 'request',
                'resource_ids' => $arena ? [$arena] : null,
                'cover_id' => Media::query()->where('static_key', $covers[$key])->value('id'),
                'published_locales' => ['ro'],
                'is_active' => true,
                'is_featured' => $d['featured'] ?? false,
                'use_in_ai' => true,
                'sort' => $d['sort'],
            ]);
        }

        return $result;
    }

    /** @param array<string, Package> $packages */
    private function addons(array $packages): void
    {
        $birthday = array_filter([$packages['standard'] ?? null, $packages['silver'] ?? null, $packages['gold'] ?? null]);
        $corporate = array_filter([$packages['corporate_team'] ?? null, $packages['corporate_challenge'] ?? null]);
        $all = [...$birthday, ...$corporate];

        $definitions = [
            ['Turneu VR de aniversare', 'Competiție VR multiplayer organizată, cu clasament, finală și premiu pentru câștigător.', 'flat', 25000, 0, 1, $birthday],
            ['Decorațiuni aniversare — Standard', null, 'flat', 30000, 0, 1, $birthday],
            ['Decorațiuni aniversare — Premium', null, 'flat', 50000, 0, 1, $birthday],
            ['Pachete-cadou pentru invitați', 'O surpriză tematică Another World pentru fiecare invitat, inclusiv o ofertă specială pentru următoarea vizită.', 'per_person', 4000, 0, 1, $birthday],
            ['Pachet foto și video', 'Fotografii și clipuri video scurte cu cele mai bune momente.', 'flat', 30000, 0, 1, $all],
            ['Timp VR suplimentar — 30 de minute', 'În funcție de disponibilitatea arenei și de mărimea grupului.', 'per_person', 5000, 30, 1, $all],
            ['Timp VR suplimentar — 60 de minute', 'În funcție de disponibilitatea arenei și de mărimea grupului.', 'per_person', 9000, 60, 1, $all],
            ['Prelungire 30 de minute', 'Prelungește experiența privată cu încă 30 de minute, în funcție de disponibilitate.', 'flat', 25000, 30, 4, $all],
        ];

        foreach ($definitions as $sort => [$name, $description, $model, $price, $minutes, $max, $attach]) {
            $addon = Addon::query()->where('name->ro', $name)->first() ?? Addon::query()->create([
                'name' => ['ro' => $name],
                'description' => $description ? ['ro' => $description] : null,
                'pricing_model' => $model,
                'price_minor' => $price,
                'extra_minutes' => $minutes,
                'max_quantity' => $max,
                'is_active' => true,
                'sort' => 10 + $sort,
            ]);
            foreach ($attach as $package) {
                $package->addons()->syncWithoutDetaching([$addon->id]);
            }
        }
    }

    private function pages(): void
    {
        $extrasBirthday = "- **Turneu VR de aniversare** — 250 lei: competiție multiplayer cu clasament, finală și premiu pentru câștigător\n"
            ."- **Decorațiuni** — Standard 300 lei, Premium 500 lei\n"
            ."- **Pachet foto și video** — 300 lei: fotografii și clipuri scurte cu cele mai bune momente\n"
            ."- **Pachete-cadou pentru invitați** — 40 lei / copil: o surpriză Another World, inclusiv o ofertă pentru următoarea vizită\n"
            ."- **Timp VR suplimentar** — 30 de minute: 50 lei / copil; 60 de minute: 90 lei / copil (în funcție de disponibilitatea arenei și de mărimea grupului)\n"
            ."- **Prelungirea petrecerii** — 250 lei / 30 de minute, în funcție de disponibilitate\n\n"
            .'Mâncare și băuturi suplimentare se pot comanda la cerere.';
        $extrasCorporate = "- **Timp VR suplimentar** — 30 de minute: 50 lei / participant; 60 de minute: 90 lei / participant (în funcție de disponibilitatea arenei și de mărimea grupului)\n"
            ."- **Prelungirea evenimentului** — 250 lei / 30 de minute, în funcție de disponibilitate\n"
            ."- **Pachet foto și video** — 300 lei: fotografii și clipuri scurte de la eveniment\n"
            .'- **Mâncare și băuturi suplimentare** — catering și băuturi la cerere';

        $this->editPage('birthdays', function (array $blocks) use ($extrasBirthday) {
            $blocks = $this->updateFirst($blocks, 'feature_split', [
                'body' => 'Sărbătoriți într-o altă lume: VR multiplayer, 2 stații PlayStation, mâncare, băuturi și toată locația doar pentru voi. Alegem împreună jocurile potrivite vârstei, iar echipa noastră îi ghidează pe copii de la primul pas.',
                'bullets' => [['text' => 'Toată locația doar pentru voi'], ['text' => 'Pizza, băuturi, gustări și cadou pentru sărbătorit'], ['text' => 'Voi aduceți tortul. Noi ne ocupăm de rest.']],
            ]);
            $blocks = $this->updateFirst($blocks, 'packages_table', ['intro' => 'Prețuri cu TVA inclus. Toate pachetele includ acces la cele 2 stații PlayStation, fără cost suplimentar.']);

            return $this->insertAfter($blocks, 'packages_table', ['type' => 'notice', 'data' => ['heading' => 'Opțiuni suplimentare', 'body' => $extrasBirthday, 'tone' => 'info']]);
        });

        $this->editPage('corporate', function (array $blocks) use ($extrasCorporate) {
            $blocks = $this->updateFirst($blocks, 'feature_split', [
                'heading' => 'Uitați de team building-ul obișnuit',
                'body' => 'Aduceți echipa într-o experiență VR multiplayer în care comunicarea, strategia și lucrul în echipă fac diferența. VR, competiție, PlayStation și toată locația doar pentru voi: pentru team building, petreceri de firmă sau o seară altfel cu colegii.',
                'bullets' => [['text' => 'Toată locația doar pentru echipa voastră'], ['text' => 'Competiție pe echipe, clasament și premiu'], ['text' => 'Factură pe numele companiei']],
            ]);
            $blocks = $this->updateFirst($blocks, 'steps', [
                'heading' => 'Cum decurge experiența',
                'steps' => [
                    ['title' => 'Primire și instructaj', 'text' => 'Echipa noastră vă explică regulile și vă ajută cu echipamentul.'],
                    ['title' => '60 de minute de VR multiplayer', 'text' => 'Intrați împreună în joc, pe echipe.'],
                    ['title' => 'VR Team Challenge și clasament', 'text' => 'Vedeți cine comunică și se coordonează cel mai bine.'],
                    ['title' => 'Mâncare, băuturi și PlayStation', 'text' => 'Timp pentru socializare, la final.'],
                ],
            ]);
            if (! collect($blocks)->contains(fn ($b) => ($b['type'] ?? null) === 'packages_table')) {
                $blocks = $this->insertAfter($blocks, 'steps', ['type' => 'packages_table', 'data' => ['heading' => 'Pachete pentru companii', 'intro' => 'Prețuri cu TVA inclus, pentru întreaga locație.', 'type' => 'event', 'empty_cta_label' => 'Cere o propunere', 'empty_cta_link' => '#cerere']]);
            }

            return $this->insertAfter($blocks, 'packages_table', ['type' => 'notice', 'data' => [
                'heading' => 'Potrivit pentru',
                'body' => "Team building, petreceri de firmă și de Crăciun, ieșiri de departament, recompense pentru angajați, after-work, celebrarea proiectelor, evenimente cu clienți și parteneri.\n\n**Opțiuni suplimentare**\n\n".$extrasCorporate,
                'tone' => 'info',
            ]]);
        });

        $this->editPage('vouchers', fn () => [
            ['type' => 'feature_split', 'data' => [
                'heading' => 'Oferă o experiență, nu doar un cadou',
                'body' => 'Cauți ceva diferit? Oferă-le prietenilor, familiei sau colegilor șansa de a intra în Another World și de a trăi realitatea virtuală multiplayer. Potrivit pentru zile de naștere, Crăciun, ocazii speciale, recompense sau pur și simplu ca surpriză.',
                'bullets' => [['text' => 'Voucher digital, gata de trimis sau de printat'], ['text' => 'Personalizat cu numele destinatarului și un scurt mesaj'], ['text' => 'Valabil 6 luni de la cumpărare']],
                'image' => Media::query()->where('static_key', 'social-casca-02-detaliu')->value('id') ?? Media::query()->where('static_key', 'headset-product')->value('id'),
                'image_side' => 'left', 'image_shape' => 'portrait', 'tone' => 'birthday',
                'primary_label' => 'Comandă un voucher', 'primary_link' => 'page:contact',
            ]],
            ['type' => 'audience_paths', 'data' => [
                'heading' => 'Alege voucherul',
                'intro' => 'Fiecare voucher include o experiență VR de 60 de minute, acces la zona de PlayStation și voucherul digital.',
                'items' => [
                    ['title' => 'Voucher VR — 110 lei', 'text' => 'Pentru 1 persoană. Experiență VR de 60 de minute și acces la zona de PlayStation.', 'image' => Media::query()->where('static_key', 'social-jucatoare-neon-rosu')->value('id'), 'link' => 'page:contact', 'link_label' => 'Comandă'],
                    ['title' => 'Voucher pentru doi — 220 lei', 'text' => 'Împărtășiți aventura: experiență VR multiplayer de 60 de minute pentru 2 persoane.', 'image' => Media::query()->where('static_key', 'social-cuplu-logo')->value('id'), 'link' => 'page:contact', 'link_label' => 'Comandă'],
                    ['title' => 'Voucher Squad — 440 lei', 'text' => 'Pentru până la 4 persoane, pentru că Another World e și mai bun cu prietenii.', 'image' => Media::query()->where('static_key', 'social-trei-prieteni-casti-ridicate')->value('id'), 'link' => 'page:contact', 'link_label' => 'Comandă'],
                    ['title' => 'Experiență privată — 880 lei', 'text' => 'Până la 8 persoane, 60 de minute, arena VR doar pentru voi și acces la 2 stații PlayStation. Perfect pentru familii, grupuri de prieteni sau ca un cadou corporate premium.', 'image' => Media::query()->where('static_key', 'social-echipa-in-linie')->value('id'), 'link' => 'page:contact', 'link_label' => 'Comandă'],
                ],
            ]],
            ['type' => 'steps', 'data' => [
                'heading' => 'Cum funcționează',
                'steps' => [
                    ['title' => 'Alegi voucherul', 'text' => 'Varianta potrivită pentru cel căruia îi faci cadoul.'],
                    ['title' => 'Primești voucherul', 'text' => 'Personalizat și livrat digital, gata de trimis sau de printat.'],
                    ['title' => 'Se rezervă experiența', 'text' => 'Destinatarul ne contactează sau rezervă ora dorită, în limita locurilor.'],
                    ['title' => 'Intră în Another World', 'text' => 'Mai rămâne doar să vină, să pună casca și să înceapă aventura.'],
                ],
            ]],
            ['type' => 'notice', 'data' => [
                'heading' => 'Condiții pentru vouchere',
                'body' => "- Voucherele sunt valabile 6 luni de la data cumpărării.\n- Este necesară rezervarea în avans, în funcție de disponibilitate.\n- Voucherul se folosește pentru experiența VR trecută pe el.\n- Voucherele nu se returnează și nu se pot schimba pe bani.\n- Voucherul poate fi personalizat cu numele destinatarului și un scurt mesaj.\n- Fiecare voucher are un cod unic, care se comunică la rezervare.",
                'tone' => 'info',
            ]],
            ['type' => 'faq_list', 'data' => ['heading' => 'Întrebări despre vouchere', 'category' => 'vouchers']],
            ['type' => 'cta_portal', 'data' => [
                'heading' => 'Oferă-le ceva ce chiar vor ține minte.',
                'text' => 'Alege. Dăruiește. Joacă. Intră în Another World.',
                'primary_label' => 'Comandă un voucher', 'primary_link' => 'page:contact',
                'secondary_label' => 'Vezi jocurile', 'secondary_link' => 'route:games',
            ]],
        ]);
        DB::table('pages')->where('key', 'vouchers')->update([
            'seo_description' => json_encode(['ro' => 'Vouchere cadou VR la Another World Cluj: de la 110 lei pentru 1 persoană până la experiența privată pentru 8 persoane. Voucher digital, valabil 6 luni.'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function faqs(): void
    {
        $updates = [
            'Unde cumpăr un voucher cadou?' => ['vouchers', 'Scrie-ne prin formularul de contact, pe e-mail sau la telefon, ori întreabă la recepție. Voucherul este personalizat cu numele destinatarului și un scurt mesaj și îl primești digital, gata de trimis sau de printat.'],
            'Cum folosesc un voucher?' => ['vouchers', 'Rezervă din timp, în funcție de disponibilitate, și spune-ne codul unic al voucherului. Voucherul este valabil 6 luni de la cumpărare, pentru experiența trecută pe el; nu se returnează și nu se schimbă pe bani.'],
        ];
        foreach ($updates as $question => [$category, $answer]) {
            Faq::query()->where('question->ro', $question)->update(['answer' => json_encode(['ro' => $answer], JSON_UNESCAPED_UNICODE)]);
        }

        $new = [
            ['birthdays', 'Ce includ pachetele pentru aniversări?', 'VR multiplayer, acces la cele 2 stații PlayStation, toată locația doar pentru voi, pizza și meniuri crispy chicken, apă, răcoritoare, gustări, masă dedicată, accesorii de petrecere, invitație digitală, cafea pentru părinți și un cadou pentru sărbătorit. Detaliile fiecărui pachet sunt pe pagina Aniversări.'],
            ['birthdays', 'Putem aduce tortul?', 'Da. Voi aduceți tortul, noi ne ocupăm de rest.'],
            ['corporate', 'Ce include un team building?', 'VR multiplayer, o provocare pe echipe cu clasament, acces la cele 2 stații PlayStation, toată locația doar pentru echipa voastră, pizza, gustări, băuturi și cafea. Pachetele Corporate Team (până la 8 participanți) și Corporate Challenge (până la 16) sunt descrise pe pagina Team building.'],
        ];
        foreach ($new as $sort => [$category, $question, $answer]) {
            if (! Faq::query()->where('question->ro', $question)->exists()) {
                Faq::query()->create(['category' => $category, 'question' => ['ro' => $question], 'answer' => ['ro' => $answer],
                    'published_locales' => ['ro'], 'is_published' => true, 'use_in_ai' => true, 'sort' => 50 + $sort]);
            }
        }
    }

    /** Aplică modificarea pe varianta publicată și pe ciornă, după o copie în istoricul paginii. */
    private function editPage(string $key, callable $change): void
    {
        $page = Page::query()->where('key', $key)->first();
        if (! $page) {
            return;
        }
        DB::table('page_revisions')->insert([
            'page_id' => $page->id,
            'snapshot' => json_encode(['title' => $page->getTranslations('title'), 'blocks' => $page->blocks], JSON_UNESCAPED_UNICODE),
            'reason' => 'published',
            'note' => 'Înainte de actualizarea cu pachetele și tarifele (02.11.2026)',
            'created_at' => now(),
        ]);
        $blocks = (array) $page->blocks;
        $blocks['ro'] = array_values($change((array) ($blocks['ro'] ?? [])));
        $page->blocks = $blocks;
        if ($page->draft_blocks) {
            $draft = (array) $page->draft_blocks;
            $draft['ro'] = $blocks['ro'];
            $page->draft_blocks = $draft;
        }
        $page->save();
    }

    private function updateFirst(array $blocks, string $type, array $data): array
    {
        foreach ($blocks as $i => $block) {
            if (($block['type'] ?? null) === $type) {
                $blocks[$i]['data'] = array_merge((array) ($block['data'] ?? []), $data);
                break;
            }
        }

        return $blocks;
    }

    private function insertAfter(array $blocks, string $type, array $new): array
    {
        $heading = $new['data']['heading'] ?? null;
        if (collect($blocks)->contains(fn ($b) => ($b['type'] ?? null) === $new['type'] && ($b['data']['heading'] ?? null) === $heading)) {
            return $blocks;
        }
        foreach ($blocks as $i => $block) {
            if (($block['type'] ?? null) === $type) {
                array_splice($blocks, $i + 1, 0, [$new]);

                return $blocks;
            }
        }
        $blocks[] = $new;

        return $blocks;
    }
};
