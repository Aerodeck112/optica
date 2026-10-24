<?php

namespace Database\Seeders;

use App\Modules\Cms\Models\Faq;
use App\Modules\Cms\Models\GalleryItem;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Conținutul de pornire al site-ului (română). Toate textele sunt editabile din panou.
 * Nu conține prețuri, capacități, vârste sau facilități neconfirmate pentru Cluj.
 */
class CmsSeeder extends Seeder
{
    private array $media = [];

    public function run(): void
    {
        $this->media = Media::query()->whereNotNull('static_key')->pluck('id', 'static_key')->all();

        foreach ($this->pages() as $sort => $page) {
            Page::query()->updateOrCreate(['key' => $page['key']], [
                'template' => 'blocks',
                'slug' => ['ro' => $page['slug']],
                'title' => ['ro' => $page['title']],
                'seo_title' => ['ro' => $page['seo_title']],
                'seo_description' => ['ro' => $page['seo_description']],
                'og_image_id' => isset($page['og']) ? $this->m($page['og']) : null,
                'blocks' => ['ro' => $page['blocks']],
                'draft_blocks' => null,
                'has_draft' => false,
                'published_locales' => ['ro'],
                'status' => 'published',
                'published_at' => now()->subMinute(),
                'is_system' => true,
                'sort' => $sort,
            ]);
        }

        $this->menus();
        $this->faqs();
        $this->gallery();
    }

    private function m(string $key): ?int
    {
        return $this->media[$key] ?? null;
    }

    private function b(string $type, array $data): array
    {
        return ['type' => $type, 'data' => $data];
    }

    private function pages(): array
    {

        return [
            [
                'key' => 'home', 'slug' => '', 'title' => 'Acasă',
                'seo_title' => 'Another World Cluj — arenă de realitate virtuală în Cluj-Napoca',
                'seo_description' => 'Intră în altă lume, împreună cu oamenii tăi. Jocuri VR multiplayer pentru prieteni, familii, aniversări și echipe, în Cluj-Napoca. Rezervă online.',
                'og' => 'cosmos-planet',
                'blocks' => [
                    $this->b('hero_portal', [
                        'location' => 'Cluj-Napoca',
                        'title_1' => 'Realitatea poate aștepta.',
                        'title_2' => 'Intră în altă lume. Împreună.',
                        'lede' => 'Arena de realitate virtuală Another World din Cluj-Napoca: aventuri, misiuni și competiții pe care le trăiești alături de prieteni, familie sau colegi.',
                        'primary_label' => 'Rezervă aventura', 'primary_link' => 'route:booking',
                        'secondary_label' => 'Descoperă jocurile', 'secondary_link' => 'route:games',
                        'aside_label' => 'Organizezi o aniversare? Vezi cum o pregătim', 'aside_link' => 'page:birthdays',
                        'poster_image' => $this->m('portal-poster'),
                        'video_sources' => [
                            ['src' => '/media/video/portal-loop-av1.mp4', 'type' => 'video/mp4; codecs="av01.0.05M.08"'],
                            ['src' => '/media/video/portal-loop.mp4', 'type' => 'video/mp4'],
                        ],
                    ]),
                    $this->b('statement', [
                        'text' => 'Nu te uiți la o poveste. Ești în ea.',
                        'body' => 'Pui casca, asculți instructajul și, în câteva momente, tu și echipa ta intrați într-o lume nouă. Colaborați, vă provocați, râdeți — iar echipa noastră vă ghidează de la primul pas.',
                    ]),
                    $this->b('audience_paths', [
                        'heading' => 'Fiecare grup are aventura lui',
                        'items' => [
                            ['title' => 'Aniversări', 'text' => 'Petrecerea pe care invitații o povestesc a doua zi. Alegem împreună jocurile potrivite vârstei.', 'image' => $this->m('kid-player-red'), 'link' => 'page:birthdays', 'link_label' => 'Planifică aniversarea'],
                            ['title' => 'Prieteni și grupuri', 'text' => 'Echipe, provocări și revanșe. Pentru o seară altfel.', 'image' => $this->m('group-arena'), 'link' => 'page:friends', 'link_label' => 'Adună echipa'],
                            ['title' => 'Familii', 'text' => 'Părinți și copii în aceeași misiune, fiecare cu rolul lui.', 'image' => $this->m('players-duo-red'), 'link' => 'route:games', 'link_label' => 'Alege jocul'],
                            ['title' => 'Companii și evenimente private', 'text' => 'Team building în care colegii chiar colaborează: strategie, comunicare și multă energie.', 'image' => $this->m('corporate-duo'), 'link' => 'page:corporate', 'link_label' => 'Cere o propunere'],
                        ],
                    ]),
                    $this->b('game_rail', ['heading' => 'Lumi în care poți intra', 'intro' => 'Jocuri multiplayer, de la aventuri pentru cei mici la misiuni intense pentru adolescenți și adulți.', 'source' => 'featured', 'limit' => 8]),
                    $this->b('feature_split', [
                        'heading' => 'Aniversarea care nu seamănă cu nicio alta',
                        'body' => 'Spune-ne câți invitați sunt și ce vârste au. Îți recomandăm jocurile potrivite și îți trimitem oferta pe e-mail.',
                        'bullets' => [['text' => 'Jocuri alese după vârsta invitaților'], ['text' => 'Ofertă clară, trimisă înainte de confirmare'], ['text' => 'Rezervarea confirmată de echipa noastră']],
                        'image' => $this->m('kids-team'), 'image_side' => 'left', 'image_shape' => 'wide', 'tone' => 'birthday',
                        'primary_label' => 'Cere ofertă', 'primary_link' => 'page:birthdays',
                    ]),
                    $this->b('image_band', ['image' => $this->m('cosmos-cliffs'), 'text' => 'Aici, realitatea e doar punctul de plecare.']),
                    $this->b('feature_split', [
                        'heading' => 'Seara în care toată lumea joacă',
                        'body' => 'Strânge-ți prietenii, alegeți misiunea și vedeți cine rezistă până la final. Cooperare sau competiție — voi decideți.',
                        'bullets' => [['text' => 'Rezervare online, în câteva minute'], ['text' => 'Aventură, SF, acțiune sau jocuri de cooperare'], ['text' => 'Nu ai mai jucat VR? Nu e nevoie de experiență.']],
                        'image' => $this->m('friends-after-game'), 'image_side' => 'right', 'image_shape' => 'portrait', 'tone' => 'default',
                        'primary_label' => 'Rezervă pentru grup', 'primary_link' => 'route:booking',
                    ]),
                    $this->b('feature_split', [
                        'heading' => 'Team building care chiar unește echipa',
                        'body' => 'Provocări în echipă, comunicare sub presiune și o poveste comună de spus la birou. Spune-ne câți colegi sunteți și pregătim propunerea.',
                        'bullets' => [['text' => 'Propunere adaptată grupului vostru'], ['text' => 'Factură pe numele companiei'], ['text' => 'Detaliile se stabilesc împreună cu echipa noastră']],
                        'image' => $this->m('players-team'), 'image_side' => 'left', 'image_shape' => 'wide', 'tone' => 'corporate',
                        'primary_label' => 'Cere o propunere', 'primary_link' => 'page:corporate',
                    ]),
                    $this->b('steps', [
                        'heading' => 'Cum funcționează',
                        'steps' => [
                            ['title' => 'Rezervi online', 'text' => 'Alegi experiența, ziua și ora. Primești confirmarea pe e-mail.'],
                            ['title' => 'Ajungi la arenă', 'text' => 'Vino cu câteva minute mai devreme, ca să începeți la timp.'],
                            ['title' => 'Instructaj și echipare', 'text' => 'Echipa îți arată cum funcționează echipamentul și regulile de siguranță.'],
                            ['title' => 'Intri în altă lume', 'text' => 'Restul e aventura voastră.'],
                        ],
                    ]),
                    $this->b('gallery_mosaic', [
                        'heading' => 'Momente din universul Another World',
                        'images' => array_values(array_filter(array_map(fn ($k) => $this->m($k), ['player-woman-red', 'group-logo', 'headsets-row', 'duo-cosmic', 'friends-floor', 'player-reach', 'logo-wall', 'teen-smile', 'players-action']))),
                    ]),
                    $this->b('testimonials', ['heading' => 'Ce spun jucătorii', 'limit' => 6]),
                    $this->b('faq_list', ['heading' => 'Întrebări frecvente', 'category' => 'all', 'limit' => 6]),
                    $this->b('cta_portal', ['heading' => 'Gata de altă lume?', 'text' => 'Alege ziua, adună echipa și rezervă aventura.', 'primary_label' => 'Rezervă aventura', 'primary_link' => 'route:booking', 'secondary_label' => 'Vezi jocurile', 'secondary_link' => 'route:games']),
                ],
            ],
            [
                'key' => 'birthdays', 'slug' => 'aniversari', 'title' => 'Aniversări în realitatea virtuală',
                'seo_title' => 'Aniversări VR pentru copii și adolescenți în Cluj-Napoca',
                'seo_description' => 'Organizează o aniversare în realitatea virtuală la Another World Cluj. Jocuri potrivite vârstei invitaților, ofertă pe e-mail și rezervare confirmată de echipă.',
                'og' => 'kids-team',
                'blocks' => [
                    $this->b('feature_split', [
                        'heading' => 'Petrecerea pe care invitații o povestesc a doua zi',
                        'body' => 'Copiii (și adulții) intră împreună într-o altă lume: misiuni, competiții și aventuri alese după vârstă și preferințe. Tu ne spui câți invitați sunt, noi te ajutăm cu restul.',
                        'bullets' => [['text' => 'Jocuri potrivite vârstei invitaților'], ['text' => 'Ofertă trimisă pe e-mail, cu toate detaliile'], ['text' => 'Confirmare de la echipa noastră înainte de eveniment']],
                        'image' => $this->m('kid-player-red'), 'image_side' => 'left', 'image_shape' => 'wide', 'tone' => 'birthday',
                        'primary_label' => 'Cere ofertă', 'primary_link' => '#cerere',
                    ]),
                    $this->b('steps', [
                        'heading' => 'Cum organizăm aniversarea',
                        'steps' => [
                            ['title' => 'Trimiți cererea', 'text' => 'Data dorită, numărul invitaților și categoriile de vârstă.'],
                            ['title' => 'Primești oferta', 'text' => 'Pachetul potrivit, durata și prețul, pe e-mail.'],
                            ['title' => 'Confirmăm rezervarea', 'text' => 'După acceptarea ofertei și, dacă este cazul, plata avansului.'],
                            ['title' => 'Sărbătoriți în altă lume', 'text' => 'Echipa face instructajul și vă ghidează pe tot parcursul jocului.'],
                        ],
                    ]),
                    $this->b('packages_table', ['heading' => 'Pachete pentru aniversări', 'type' => 'birthday', 'empty_cta_label' => 'Cere ofertă', 'empty_cta_link' => '#cerere']),
                    $this->b('notice', [
                        'heading' => 'Bine de știut pentru părinți',
                        'body' => "- Rezervarea și comunicarea se fac prin adultul responsabil de grup.\n- Fiecare joc are o vârstă minimă recomandată; o confirmăm împreună cu tine înainte de eveniment.\n- Regulile de siguranță și condițiile pentru participarea minorilor sunt disponibile înainte de rezervare.\n- Nu avem nevoie de datele personale ale copiilor — doar de numărul participanților pe categorii de vârstă.",
                        'tone' => 'info',
                    ]),
                    $this->b('gallery_mosaic', [
                        'heading' => 'Aniversări în rețeaua Another World',
                        'images' => array_values(array_filter(array_map(fn ($k) => $this->m($k), ['kid-player-blue', 'kids-team', 'teen-smile', 'teens-duo', 'briefing-headset']))),
                    ]),
                    $this->b('faq_list', ['heading' => 'Întrebări despre aniversări', 'category' => 'birthdays']),
                    $this->b('lead_form', ['type' => 'birthday', 'heading' => 'Cere ofertă pentru aniversare', 'intro' => 'Completează câteva detalii și îți trimitem oferta pe e-mail. Cererea nu ocupă încă arena; rezervarea se confirmă după acceptarea ofertei.', 'anchor' => 'cerere']),
                ],
            ],
            [
                'key' => 'friends', 'slug' => 'prieteni-si-grupuri', 'title' => 'Prieteni și grupuri',
                'seo_title' => 'Joc VR cu prietenii în Cluj-Napoca — Another World',
                'seo_description' => 'Aventuri și competiții în realitatea virtuală pentru grupuri de prieteni. Alege jocul, ziua și ora și rezervă online.',
                'og' => 'group-arena',
                'blocks' => [
                    $this->b('feature_split', [
                        'heading' => 'Seara în care toată lumea joacă',
                        'body' => 'Fie că vreți să colaborați într-o misiune grea sau să vedeți cine are cele mai bune reflexe, aici găsiți jocul potrivit pentru grupul vostru.',
                        'bullets' => [['text' => 'Rezervare online, cu ore reale disponibile'], ['text' => 'Jocuri de cooperare sau competiție'], ['text' => 'Instructaj înainte de joc — nu e nevoie de experiență']],
                        'image' => $this->m('friends-floor'), 'image_side' => 'right', 'image_shape' => 'wide',
                        'primary_label' => 'Rezervă aventura', 'primary_link' => 'route:booking',
                    ]),
                    $this->b('game_rail', ['heading' => 'Alege misiunea', 'source' => 'featured', 'limit' => 10]),
                    $this->b('image_band', ['image' => $this->m('cosmos-red-planet'), 'text' => 'Echipa voastră, o lume nouă.']),
                    $this->b('lead_form', ['type' => 'group', 'heading' => 'Sunteți un grup mare?', 'intro' => 'Pentru grupuri numeroase sau cereri speciale, trimite-ne detaliile și revenim cu o propunere.', 'anchor' => 'cerere']),
                    $this->b('cta_portal', ['heading' => 'Cine intră primul?', 'primary_label' => 'Rezervă aventura', 'primary_link' => 'route:booking']),
                ],
            ],
            [
                'key' => 'corporate', 'slug' => 'team-building-evenimente', 'title' => 'Team building și evenimente private',
                'seo_title' => 'Team building VR în Cluj-Napoca — Another World',
                'seo_description' => 'Team building și evenimente private în realitatea virtuală, în Cluj-Napoca. Propunere adaptată grupului vostru și factură pe numele companiei.',
                'og' => 'players-team',
                'blocks' => [
                    $this->b('feature_split', [
                        'heading' => 'Team building care chiar unește echipa',
                        'body' => 'Misiunile în realitatea virtuală pun accent pe comunicare, coordonare și decizii rapide. Colegii joacă împreună, nu unul lângă altul — și pleacă acasă cu o poveste comună.',
                        'bullets' => [['text' => 'Propunere adaptată numărului de participanți'], ['text' => 'Factură pe numele companiei'], ['text' => 'Un singur interlocutor pentru organizare']],
                        'image' => $this->m('players-team'), 'image_side' => 'left', 'image_shape' => 'wide', 'tone' => 'corporate',
                        'primary_label' => 'Cere o propunere', 'primary_link' => '#cerere',
                    ]),
                    $this->b('steps', [
                        'heading' => 'Cum lucrăm cu echipele',
                        'steps' => [
                            ['title' => 'Ne spui ce urmăriți', 'text' => 'Numărul de colegi, data dorită și tipul de experiență.'],
                            ['title' => 'Primești propunerea', 'text' => 'Program, durată și ofertă de preț.'],
                            ['title' => 'Confirmăm împreună', 'text' => 'Rezervarea se confirmă după acceptarea ofertei.'],
                            ['title' => 'Echipa intră în joc', 'text' => 'Noi ne ocupăm de instructaj și de desfășurarea jocului.'],
                        ],
                    ]),
                    $this->b('faq_list', ['heading' => 'Întrebări frecvente', 'category' => 'corporate']),
                    $this->b('lead_form', ['type' => 'corporate', 'heading' => 'Cere o propunere pentru echipa ta', 'intro' => 'Răspundem pe e-mail, cu o propunere adaptată grupului.', 'anchor' => 'cerere']),
                ],
            ],
            [
                'key' => 'pricing', 'slug' => 'tarife', 'title' => 'Tarife și pachete',
                'seo_title' => 'Tarife și pachete VR — Another World Cluj',
                'seo_description' => 'Pachete și tarife pentru experiențele de realitate virtuală Another World Cluj: sesiuni, aniversări și evenimente.',
                'blocks' => [
                    $this->b('packages_table', ['heading' => null, 'intro' => 'Prețurile includ TVA. Durata rezervării cuprinde instructajul, echiparea și jocul; durata efectivă a jocului este afișată separat.', 'type' => 'all', 'empty_cta_label' => 'Cere ofertă', 'empty_cta_link' => 'page:contact']),
                    $this->b('notice', ['heading' => 'Plată și avans', 'body' => 'Metodele de plată disponibile și eventualul avans sunt afișate în rezumatul rezervării, înainte de confirmare. Condițiile de anulare și reprogramare sunt descrise în politica de rezervare.', 'tone' => 'info']),
                    $this->b('cta_portal', ['heading' => 'Ai ales?', 'primary_label' => 'Rezervă aventura', 'primary_link' => 'route:booking', 'secondary_label' => 'Aniversări', 'secondary_link' => 'page:birthdays']),
                ],
            ],
            [
                'key' => 'vouchers', 'slug' => 'vouchere-cadou', 'title' => 'Vouchere cadou',
                'seo_title' => 'Voucher cadou VR — Another World Cluj',
                'seo_description' => 'Oferă o aventură în realitatea virtuală. Vouchere cadou Another World Cluj, disponibile la recepția arenei.',
                'og' => 'headset-product',
                'blocks' => [
                    $this->b('feature_split', [
                        'heading' => 'Oferă o aventură cadou',
                        'body' => 'Un cadou pe care îl trăiești, nu doar îl desfaci. Voucherele cadou sunt disponibile la recepția arenei, pentru o valoare sau pentru o experiență.',
                        'bullets' => [['text' => 'Disponibile la recepția arenei'], ['text' => 'Condițiile de utilizare și valabilitatea sunt trecute pe voucher'], ['text' => 'Pot fi folosite la rezervare, conform condițiilor']],
                        'image' => $this->m('headset-product'), 'image_side' => 'left', 'image_shape' => 'portrait', 'tone' => 'birthday',
                        'primary_label' => 'Scrie-ne pentru un voucher', 'primary_link' => 'page:contact',
                    ]),
                    $this->b('faq_list', ['heading' => 'Întrebări despre vouchere', 'category' => 'vouchers']),
                ],
            ],
            [
                'key' => 'gallery', 'slug' => 'galerie', 'title' => 'Galerie',
                'seo_title' => 'Galerie foto — Another World Cluj',
                'seo_description' => 'Imagini din universul Another World: jucători în acțiune și lumile din jocurile de realitate virtuală.',
                'blocks' => [
                    $this->b('gallery_mosaic', ['heading' => 'Jucători în acțiune', 'album' => 'general', 'limit' => 24]),
                    $this->b('gallery_mosaic', ['heading' => 'Lumile din jocuri', 'album' => 'games', 'limit' => 24]),
                ],
            ],
            [
                'key' => 'about', 'slug' => 'despre', 'title' => 'Despre Another World Cluj',
                'seo_title' => 'Despre Another World Cluj',
                'seo_description' => 'Another World Cluj este locația din Cluj-Napoca a rețelei internaționale de arene de realitate virtuală Another World.',
                'blocks' => [
                    $this->b('statement', ['text' => 'Un loc în care realitatea virtuală se trăiește împreună.', 'body' => 'Another World Cluj face parte din rețeaua internațională Another World, dezvoltată în jurul jocurilor de realitate virtuală pentru grupuri. Aici aducem aceste experiențe în Cluj-Napoca: pentru prieteni, familii, aniversări și echipe.']),
                    $this->b('feature_split', [
                        'heading' => 'Ce ne propunem',
                        'body' => 'Să transformăm o ieșire obișnuită într-o amintire comună. Fiecare grup primește instructaj, însoțire pe durata jocului și ajutor în alegerea experienței potrivite.',
                        'bullets' => [['text' => 'Experiențe pentru grupuri, nu pentru spectatori'], ['text' => 'Jocuri alese după vârstă și preferințe'], ['text' => 'Siguranța jucătorilor pe primul loc']],
                        'image' => $this->m('logo-wall'), 'image_side' => 'right', 'image_shape' => 'wide',
                    ]),
                    $this->b('image_band', ['image' => $this->m('cosmos-city'), 'text' => 'O rețea internațională. O arenă în Cluj.']),
                    $this->b('cta_portal', ['heading' => 'Vino să vezi cu ochii tăi', 'primary_label' => 'Rezervă aventura', 'primary_link' => 'route:booking', 'secondary_label' => 'Contact', 'secondary_link' => 'page:contact']),
                ],
            ],
            [
                'key' => 'faq', 'slug' => 'intrebari-frecvente', 'title' => 'Întrebări frecvente',
                'seo_title' => 'Întrebări frecvente — Another World Cluj',
                'seo_description' => 'Răspunsuri despre rezervări, aniversări, siguranță și vouchere la arena de realitate virtuală Another World Cluj.',
                'blocks' => [
                    $this->b('faq_list', ['heading' => null, 'category' => 'all', 'grouped' => true]),
                    $this->b('cta_portal', ['heading' => 'Nu ai găsit răspunsul?', 'text' => 'Scrie-ne și îți răspundem.', 'primary_label' => 'Contactează-ne', 'primary_link' => 'page:contact']),
                ],
            ],
            [
                'key' => 'contact', 'slug' => 'contact', 'title' => 'Contact',
                'seo_title' => 'Contact — Another World Cluj',
                'seo_description' => 'Date de contact, program și formular pentru Another World Cluj, arena de realitate virtuală din Cluj-Napoca.',
                'blocks' => [
                    $this->b('contact_details', ['heading' => 'Hai pe la noi', 'intro' => 'Pentru rezervări rapide folosește pagina de rezervare. Pentru orice altă întrebare, scrie-ne.']),
                    $this->b('contact_form', ['heading' => 'Scrie-ne']),
                ],
            ],
        ];
    }

    private function menus(): void
    {
        $pages = Page::query()->pluck('id', 'key');
        $set = function (string $location, string $name, array $items) use ($pages) {
            $menu = Menu::query()->updateOrCreate(['location' => $location], ['name' => $name]);
            $menu->items()->delete();
            foreach ($items as $sort => [$label, $type, $target]) {
                MenuItem::query()->create([
                    'menu_id' => $menu->id,
                    'label' => ['ro' => $label],
                    'link_type' => $type,
                    'page_id' => $type === 'page' ? $pages[$target] ?? null : null,
                    'route' => $type === 'route' ? $target : null,
                    'url' => $type === 'url' ? $target : null,
                    'sort' => $sort,
                ]);
            }
        };

        $set('header', 'Meniu principal', [
            ['Jocuri VR', 'route', 'games'],
            ['Aniversări', 'page', 'birthdays'],
            ['Prieteni', 'page', 'friends'],
            ['Companii', 'page', 'corporate'],
            ['Tarife', 'page', 'pricing'],
            ['Vouchere', 'page', 'vouchers'],
            ['Contact', 'page', 'contact'],
        ]);
        $set('footer_explore', 'Subsol — Descoperă', [
            ['Jocuri VR', 'route', 'games'],
            ['Aniversări', 'page', 'birthdays'],
            ['Prieteni și grupuri', 'page', 'friends'],
            ['Team building și evenimente', 'page', 'corporate'],
            ['Tarife și pachete', 'page', 'pricing'],
            ['Vouchere cadou', 'page', 'vouchers'],
            ['Galerie', 'page', 'gallery'],
        ]);
        $set('footer_info', 'Subsol — Informații', [
            ['Despre noi', 'page', 'about'],
            ['Întrebări frecvente', 'page', 'faq'],
            ['Contact', 'page', 'contact'],
            ['Contul meu', 'route', 'account'],
        ]);
    }

    private function faqs(): void
    {
        $faqs = [
            ['general', 'Am nevoie de experiență în realitatea virtuală?', 'Nu. Înainte de joc, echipa îți explică pas cu pas cum funcționează echipamentul și regulile. Jocurile sunt gândite pentru a fi înțelese repede, chiar dacă joci pentru prima dată.'],
            ['general', 'Cum mă îmbrac?', 'Recomandăm haine și încălțăminte comode, în care te poți mișca ușor.'],
            ['general', 'Pot juca dacă port ochelari de vedere?', 'Spune-ne la rezervare dacă porți ochelari, iar echipa îți confirmă ce opțiuni ai pentru echipamentul folosit în arenă.'],
            ['general', 'Câți jucători pot participa?', 'Numărul de jucători diferă de la un joc și de la un pachet la altul. Îl vezi la rezervare, iar pentru grupuri mari ne poți trimite o cerere.'],
            ['booking', 'Cum rezerv?', 'Online, din pagina de rezervare: alegi experiența, numărul de participanți, ziua și ora. Pentru aniversări și evenimente poți trimite o cerere de ofertă.'],
            ['booking', 'Cât durează experiența?', 'Durata rezervării include instructajul, echiparea și jocul propriu-zis. Durata efectivă a jocului este afișată separat, la fiecare joc și pachet.'],
            ['booking', 'Pot modifica sau anula rezervarea?', 'Da, conform politicii de rezervare. Linkul de gestionare primit pe e-mail îți arată ce poți face online; pentru alte situații, contactează-ne.'],
            ['booking', 'Ce se întâmplă dacă întârzii?', 'Anunță-ne cât mai repede. Ce se poate face depinde de programul arenei din ziua respectivă și de regulile din politica de rezervare.'],
            ['birthdays', 'Pentru ce vârste sunt potrivite aniversările?', 'Avem jocuri pentru vârste diferite. Fiecare joc are o vârstă minimă recomandată, pe care o confirmăm împreună când stabilim programul.'],
            ['birthdays', 'Cine face rezervarea pentru aniversarea unui copil?', 'Un adult responsabil (părinte sau tutore). Nu avem nevoie de datele personale ale copiilor — doar de numărul participanților pe categorii de vârstă.'],
            ['safety', 'Este sigur?', 'Înainte de joc, fiecare participant primește instructajul de siguranță, iar echipa urmărește jocul pe tot parcursul lui. Te rugăm să citești și informarea de siguranță înainte de vizită.'],
            ['safety', 'Există situații în care nu ar trebui să joc?', 'Persoanele cu anumite afecțiuni (de exemplu epilepsie fotosensibilă), femeile însărcinate sau cei care nu se simt bine ar trebui să citească informarea de siguranță și, la nevoie, să consulte un medic înainte de a juca. Nu cerem și nu stocăm date medicale.'],
            ['vouchers', 'Unde cumpăr un voucher cadou?', 'Voucherele cadou sunt disponibile la recepția arenei. Scrie-ne dacă vrei să pregătim unul pentru tine.'],
            ['vouchers', 'Cum folosesc un voucher?', 'Spune-ne codul voucherului la rezervare sau la recepție. Condițiile și valabilitatea sunt trecute pe voucher și în condițiile pentru vouchere.'],
            ['corporate', 'Emiteți factură pentru companie?', 'Da. Completează datele companiei în cerere sau la rezervare, iar factura se emite pe numele companiei.'],
            ['corporate', 'Putem organiza un eveniment privat?', 'Trimite-ne o cerere cu data, numărul de participanți și ce vă doriți, iar echipa revine cu o propunere.'],
        ];
        foreach ($faqs as $sort => [$category, $q, $a]) {
            (Faq::query()->where('question->ro', $q)->first() ?? new Faq)->fill([
                'category' => $category,
                'question' => ['ro' => $q],
                'answer' => ['ro' => $a],
                'published_locales' => ['ro'],
                'is_published' => true,
                'use_in_ai' => true,
                'sort' => $sort,
            ])->save();
        }
    }

    private function gallery(): void
    {
        GalleryItem::query()->delete();
        $general = ['player-woman-red', 'players-duo-red', 'group-arena', 'duo-cosmic', 'friends-floor', 'player-reach', 'group-logo', 'headsets-row', 'players-action', 'couple-after-game', 'teens-duo', 'logo-wall', 'kid-player-blue', 'friends-after-game', 'headsets-shelf', 'player-logo', 'arena-purple'];
        $games = ['game-shmooter-cubes-1', 'game-kernel-raven-1', 'game-tavern-1', 'game-ghost-tower-dance-1', 'game-shmooter-space-battle-2-1', 'game-starbase-survival-1', 'game-shmooter-forts-2', 'game-colony-code-red-1', 'game-ghost-tower-fishing-1', 'game-kernel-bunker-2'];
        foreach ([['general', $general], ['games', $games]] as [$album, $keys]) {
            foreach ($keys as $sort => $key) {
                if ($id = $this->m($key)) {
                    GalleryItem::query()->create(['media_id' => $id, 'album' => $album, 'is_published' => true, 'sort' => $sort]);
                }
            }
        }
    }
}
