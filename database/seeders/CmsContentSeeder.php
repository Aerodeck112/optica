<?php

namespace Database\Seeders;

use App\Domain\Cms\MediaLibrary;
use App\Domain\Cms\Sections;
use App\Models\Media;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Post;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Content of https://optica-optofarm.ro (RO + /hu/) on 2026-10-17, rebuilt as CMS pages with the
 * original images stored locally (GEN-01). Corrections confirmed by the beneficiary are applied
 * (DECISIONS.md N-01…N-08). Idempotent: existing pages, media and menus are never overwritten.
 */
class CmsContentSeeder extends Seeder
{
    private array $media = [];

    public function run(): void
    {
        if (Page::query()->exists()) {
            return;
        }

        DB::transaction(function () {
            $this->importMedia();
            $this->attachServiceImages();

            foreach ($this->pages() as $key => [$slugRo, $slugHu, $template, $title, $sections]) {
                $content = Sections::emptyPage($title[0], $title[1]);
                $content['sections'] = $sections;
                $page = Page::create([
                    'key' => $key,
                    'slug_ro' => $slugRo,
                    'slug_hu' => $slugHu,
                    'template' => $template,
                    'draft' => $content,
                    'published' => $content,
                    'published_at' => now(),
                    'is_system' => true,
                ]);
                PageVersion::create(['page_id' => $page->id, 'number' => 1, 'content' => $content, 'note' => 'Import optica-optofarm.ro', 'created_at' => now()]);
            }

            $this->menus();
            $this->offers();
            $this->redirects();
        });
    }

    private function importMedia(): void
    {
        $alt = [
            '079' => ['Interiorul unui magazin Optica Optofarm', 'Optica Optofarm üzlet belső tere'],
            'LOCALMURES-Optica-Optofarm-PRODUCTIE029-Copy' => ['Echipament de tratare a lentilelor în fabrica proprie', 'Lencsebevonó berendezés a saját lencsegyárban'],
            'WhatsApp-Image-2024-08-06-at-14.56.49-2-2' => ['Aparatură de examinare oftalmologică', 'Szemészeti vizsgálóeszköz'],
            'eyeglasses-hard-and-soft-storage-case-on-blue-bac-2026-03-18-17-44-54-utc' => ['Ochelari și tocuri de protecție', 'Szemüvegek és tokok'],
            '49-home-before' => ['Stradă văzută neclar', 'Homályosan látott utca'],
            '49-home-after' => ['Aceeași stradă văzută clar', 'Ugyanaz az utca élesen'],
            'WhatsApp-Image-2025-06-16-at-09.59.24-1' => ['Hala de producție a fabricii de lentile', 'A lencsegyár gyártócsarnoka'],
            'WhatsApp-Image-2025-06-16-at-09.59.39-1' => ['Utilaje în fabrica de lentile', 'Gépek a lencsegyárban'],
            'VEGSO-ATTILA-RO-SZOROLAP-5' => ['Afiș Optica Optofarm despre terapia vizuală', 'Optica Optofarm szórólap a látásterápiáról'],
            'RO-WEBPAGE-DESCRIPTION-30-1' => ['Afișul campaniei de început de școală', 'Az iskolakezdési akció plakátja'],
            '775261296_1671399441660959_8693204252077607192_n-e1788097293599' => ['Campania de început de școală', 'Iskolakezdési akció'],
        ];
        $library = app(MediaLibrary::class);
        foreach (glob(resource_path('site-import/images/*.webp')) as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            [$ro, $hu] = $alt[$name] ?? ['', ''];
            $this->media[$name] = $library->storeFile($file, basename($file), ['ro' => $ro, 'hu' => $hu])->id;
        }
    }

    private function attachServiceImages(): void
    {
        // Same order as the images on the current Services page.
        $order = ['E10AN403', 'E113OHNZ', '3J3A7134', 'E1088TNV', 'Ocular_Ultrasound_B-Scan-1200x628-Facebook', 'E1142P0V', 'E107JNI0', 'E11KUKU', 'E10JVWZD', 'E1090GZY', 'E10HWC4V', 'E10K2MF'];
        foreach (Service::orderBy('sort_order')->get()->values() as $i => $service) {
            $name = $order[$i] ?? null;
            if ($name && isset($this->media[$name]) && ! $service->image_id) {
                $service->update(['image_id' => $this->media[$name]]);
                Media::whereKey($this->media[$name])->update(['alt' => ['ro' => $service->name('ro'), 'hu' => $service->name('hu')]]);
            }
        }
    }

    private function m(string $name): ?int
    {
        return $this->media[$name] ?? null;
    }

    private function section(string $type, array $ro, array $hu, array $data = []): array
    {
        $section = Sections::make($type);
        $section['text']['ro'] = array_merge($section['text']['ro'], $ro);
        $section['text']['hu'] = array_merge($section['text']['hu'], $hu);
        $section['data'] = array_merge($section['data'], $data);

        return $section;
    }

    private function pages(): array
    {
        $legal = json_decode(file_get_contents(resource_path('site-import/legal.json')), true);
        $legalSections = function (array $doc) {
            // N-08: the current site has no Hungarian text for the legal pages; the Romanian text is
            // shown under the Hungarian title until the beneficiary supplies a validated translation.
            return array_map(fn ($s) => $this->section('rich_text', ['heading' => $s['heading'], 'body' => $s['body']], ['heading' => $s['heading'], 'body' => $s['body']]), $doc['sections']);
        };

        return [
            'home' => ['', '', 'home', ['Acasă', 'Kezdőlap'], [
                $this->section('hero',
                    ['heading' => 'Expertul vederii de 35 de ani', 'body' => 'Oftalmologi experimentați, ochelari confecționați de noi, rame și examene oftalmologice pentru întreaga familie.', 'button_label' => 'Programare online'],
                    ['heading' => '35 éve a látás szakértője', 'body' => 'Szakértő szemészeti ellátás, személyre szabott keretek és professzionális szemvizsgálat az egész családnak.', 'button_label' => 'Online időpontfoglalás'],
                    ['image_id' => $this->m('079'), 'button_target' => 'booking', 'show_phones' => true]),
                $this->section('text_image',
                    ['heading' => 'O experiență construită în jurul vederii tale', 'body' => 'De 35 de ani, Optica Optofarm combină experiența, tehnologia modernă și pasiunea pentru sănătatea ochilor pentru a oferi soluții complete pentru nevoile tale vizuale. Avem propria noastră fabrică, ceea ce ne permite să controlăm cu atenție fiecare etapă a procesului și să acordăm o atenție deosebită calității și preciziei.', 'button_label' => 'Descoperă echipa'],
                    ['heading' => '35 év tapasztalat a tisztább látásért', 'body' => 'Az Optica Optofarm 35 éve ötvözi a szakmai tapasztalatot, a modern technológiát és a szem egészsége iránti elkötelezettséget, hogy teljes körű megoldást nyújtson látásigényeidre. Saját lencsegyártó üzemünknek köszönhetően a folyamat minden lépését kézben tartjuk, kiemelt figyelmet fordítva a pontosságra és a minőségre.', 'button_label' => 'Ismerje meg a csapatunkat'],
                    ['image_id' => $this->m('WhatsApp-Image-2024-08-06-at-14.56.49-2-2'), 'image_side' => 'right', 'button_target' => 'page:doctors']),
                $this->section('services',
                    ['heading' => 'Consultații și tratamente oftalmologice', 'body' => 'De la un simplu control al vederii până la investigații oftalmologice complete, suntem aici să te ajutăm să vezi și să te simți bine.'],
                    ['heading' => 'Szemészeti vizsgálatok és kezelések', 'body' => 'Az egyszerű látásellenőrzéstől az összetett szakorvosi kivizsgálásokig mindenben melletted állunk.']),
                $this->section('text_image',
                    ['heading' => 'Timp pentru tine', 'body' => 'O consultație bună înseamnă mai mult decât câteva măsurători. Te ascultăm, îți explicăm și îți recomandăm ceea ce ți se potrivește.', 'button_label' => 'Fă o programare'],
                    ['heading' => 'Időt szánunk rád', 'body' => 'Egy alapos vizsgálat többet jelent néhány egyszerű mérésnél. Meghallgatunk, elmagyarázzuk a lehetőségeket, és azt ajánljuk, ami számodra a legmegfelelőbb.', 'button_label' => 'Időpontfoglalás'],
                    ['image_id' => $this->m('E10AN403'), 'image_side' => 'left', 'button_target' => 'booking']),
                $this->section('text_image',
                    ['heading' => 'Lentile de precizie, produse în fabrica proprie', 'body' => 'Producem lentile în propria fabrică, personalizate după prescripția ta, rama aleasă și felul în care folosești ochelarii.', 'button_label' => 'Fabrica noastră'],
                    ['heading' => 'Saját gyártású, személyre szabott lencsék', 'body' => 'Lencséinket saját gyárunkban készítjük, egyedi igényeidhez igazítva.', 'button_label' => 'Lencsegyárunk'],
                    ['image_id' => $this->m('LOCALMURES-Optica-Optofarm-PRODUCTIE029-Copy'), 'image_side' => 'right', 'button_target' => 'page:factory']),
                $this->section('locations',
                    ['heading' => 'Ne găsești în 8 locații', 'body' => 'Alege locația care îți este la îndemână și lasă-ne să avem grijă de vederea ta, de la consultație până la ochelarii finali.'],
                    ['heading' => '8 helyszínen várunk', 'body' => 'Válaszd ki a számodra legközelebb eső helyszínt, és bízd ránk a látásod gondozását.']),
                $this->section('rich_text',
                    ['heading' => 'Ochelari potriviți. Grijă reală. Pentru fiecare generație.', 'body' => "Credem că o experiență bună la optică înseamnă mai mult decât alegerea unei rame și a unor lentile. Înseamnă să fii ascultat, să primești recomandarea potrivită și să ai mereu pe cineva la care să poți apela.\n\n- **Prețuri accesibile pentru toate vârstele.** Oferim soluții optice pentru copii, adulți și seniori.\n- **Atenție acordată fiecărui client.** Nu ne grăbim să vindem o pereche de ochelari. Îți ascultăm nevoile și te ajutăm să alegi soluția potrivită pentru tine, stilul tău de viață și confortul tău vizual.\n- **Suntem alături de tine și după achiziție.** Îți oferim în continuare ajutor pentru ajustarea și reglarea ochelarilor.\n- **Experiență și servicii complete.** De 35 de ani, combinăm experiența specialiștilor noștri, tehnologia modernă și producția proprie pentru a oferi soluții complete pentru sănătatea și confortul ochilor tăi."],
                    ['heading' => 'Megfelelő szemüveg minden generáció számára', 'body' => "Hiszünk abban, hogy egy jó vásárlási élmény többet jelent, mint csupán a megfelelő keret és lencse kiválasztása. Azt jelenti, hogy meghallgatunk, személyre szabott tanácsot kapsz, és mindig van valaki, akihez bizalommal fordulhatsz.\n\n- **Megfizethető megoldások minden korosztály számára.** Gyermekek, felnőttek és idősebbek számára egyaránt kínálunk személyre szabott megoldásokat.\n- **Figyelünk arra, ami neked fontos.** Nem az a célunk, hogy minél gyorsabban eladjunk egy szemüveget. Megismerjük az igényeidet, és segítünk megtalálni azt a keretet és lencsét, amely leginkább illik az életviteledhez és a stílusodhoz.\n- **A szemüveg elkészülte után is számíthatsz ránk.** Ha szükség van rá, segítünk a szemüveg igazításában és a megfelelő viselési komfort kialakításában.\n- **35 év tapasztalat és komplexitás.** A vizsgálattól a megfelelő lencse és keret kiválasztásán át a kész szemüvegig komplex szolgáltatást nyújtunk."]),
                $this->section('rich_text',
                    ['heading' => 'Tehnologie de ultimă generație și soluții pe care nu le găsești oriunde', 'body' => "La Optica Optofarm, am fost printre primii din județul Mureș care au adoptat tehnologii moderne pentru chirurgia cataractei și investigațiile oftalmologice. Astăzi continuăm să investim în aparatură de ultimă generație și în metode de tratament care aduc un plus real pentru pacienții noștri.\n\nUn exemplu este gimnastica oculară psiho-ortoptică, un serviciu specializat pe care îl oferim într-o formă unică în regiune, pentru nevoi vizuale care necesită mai mult decât o simplă corecție cu ochelari."],
                    ['heading' => 'Modern eszközök és egyedülálló megoldások', 'body' => "A megyében az elsők között honosítottuk meg a korszerű szürkehályog-műtéteket és a legújabb diagnosztikai eljárásokat.\n\nKiemelt szolgáltatásunk a térségben hiánypótló pszicho-ortoptikai szemtorna, amely hatékony segítséget nyújt az olyan speciális látásproblémák esetén, amelyeket szemüveggel önmagában nem lehet korrigálni."]),
                $this->section('education',
                    ['heading' => 'Spune adio vederii încețoșate', 'body' => 'Trageți de cursor pentru a compara cum arată aceeași imagine văzută neclar și văzută clar.'],
                    ['heading' => 'Búcsúzz el a homályos látástól!', 'body' => 'Húzza a csúszkát, és hasonlítsa össze ugyanazt a képet homályosan és élesen látva.'],
                    ['tool' => 'blur_compare', 'image_ids' => [$this->m('49-home-before'), $this->m('49-home-after')]]),
                $this->section('cta',
                    ['heading' => 'Fă primul pas spre o vedere mai bună', 'body' => 'Alege locația și ora care ți se potrivesc.', 'button_label' => 'Programare online'],
                    ['heading' => 'Tegye meg az első lépést a jobb látásért', 'body' => 'Válassza ki az Önnek megfelelő üzletet és időpontot.', 'button_label' => 'Online időpontfoglalás'],
                    ['button_target' => 'booking']),
            ]],

            'services' => ['servicii', 'servicii', 'standard', ['Servicii', 'Szolgáltatások'], [
                $this->section('services',
                    ['heading' => 'Servicii', 'body' => 'De la un simplu control al vederii până la investigații oftalmologice complete.'],
                    ['heading' => 'Szolgáltatások', 'body' => 'Az egyszerű látásellenőrzéstől az összetett szakorvosi kivizsgálásokig.']),
                $this->section('rich_text',
                    ['heading' => 'Experți în boli și afecțiuni oculare', 'body' => "Fie că este vorba de un test oftalmologic, ochelari la modă sau lentile de contact practice, avem tot ce aveți nevoie pentru o vedere și un aspect perfect.\n\n- Oftalmologie generală\n- Optometrie digitală\n- Infecții și leziuni oculare\n- Sindromul de ochi uscat\n- Îndepărtarea corpurilor străine\n- Refracție pentru ochelari"],
                    ['heading' => 'A szembetegségek szakértői', 'body' => "Akár szemvizsgálatról, divatos szemüvegről vagy praktikus kontaktlencséről van szó, nálunk mindent megtalál, amire a tökéletes látáshoz és megjelenéshez szüksége van.\n\n- Általános szemészet\n- Digitális optometria\n- Szemfertőzések és sérülések ellátása\n- Száraz szem szindróma kezelése\n- Idegen testek eltávolítása\n- Szemüvegek fénytörése"]),
                $this->section('education',
                    ['heading' => 'Ce serviciu mi se potrivește?', 'body' => 'Răspundeți la câteva întrebări simple pentru a afla ce tip de programare să alegeți. Ghidul este informativ și nu înlocuiește consultul medical.'],
                    ['heading' => 'Melyik szolgáltatás való nekem?', 'body' => 'Válaszoljon néhány egyszerű kérdésre, és megmutatjuk, milyen időpontot érdemes foglalni. Az útmutató tájékoztató jellegű, nem helyettesíti az orvosi vizsgálatot.'],
                    ['tool' => 'service_guide']),
                $this->section('cta', ['heading' => 'Programează-te online', 'body' => '', 'button_label' => 'Programare online'], ['heading' => 'Foglaljon időpontot online', 'body' => '', 'button_label' => 'Online időpontfoglalás'], ['button_target' => 'booking']),
            ]],

            'doctors' => ['medici', 'medici', 'standard', ['Medici', 'Szakorvosaink'], [
                $this->section('rich_text',
                    ['heading' => 'Medici', 'body' => 'Optica Optofarm este la dispoziția dumneavoastră de 35 de ani, cu 8 unități în poziția de lider în județ. În plus față de corecția vederii, echipa noastră de medici foarte bine pregătiți efectuează, de asemenea, intervenții chirurgicale de cataractă prin facoemulsificare, pe lângă operații minore.'],
                    ['heading' => 'Szakorvosaink', 'body' => 'Maros megye 8 egységében dolgozó szakorvosaink és optometristáink a pontos diagnózisra, a személyre szabott kezelésre és a figyelmes betegellátásra helyezik a hangsúlyt.']),
                $this->section('text_image',
                    ['heading' => 'Știai că la Optica Optofarm a luat naștere pentru prima dată fuziunea dintre terapia vizuală și psihologie?', 'body' => "Această abordare inovatoare antrenează deopotrivă vederea și creierul, fiind un sprijin real nu doar pentru strabism sau „ochi leneș”, ci și pentru:\n\n- Îmbunătățirea atenției și depășirea dificultăților de învățare\n- Dezvoltarea reflexelor și a memoriei vizuale\n- O recuperare mai rapidă și echilibrată\n\nFă primul pas spre o vedere mai bună! Programează-te la **0365 430 939**.", 'button_label' => 'Programare online'],
                    // N-08: this paragraph exists only in Romanian on the current site; Hungarian translation to be verified.
                    ['heading' => 'Tudta, hogy a látásterápia és a pszichológia ötvözése először az Optica Optofarmnál valósult meg?', 'body' => "Ez az újszerű megközelítés egyszerre fejleszti a látást és az agyat, és nemcsak kancsalság vagy „tompalátás” esetén nyújt valódi segítséget, hanem a következőkben is:\n\n- a figyelem javítása és a tanulási nehézségek leküzdése\n- a reflexek és a vizuális memória fejlesztése\n- gyorsabb és kiegyensúlyozottabb felépülés\n\nTegye meg az első lépést a jobb látásért! Időpontfoglalás: **0365 430 939**.", 'button_label' => 'Online időpontfoglalás'],
                    ['image_id' => $this->m('VEGSO-ATTILA-RO-SZOROLAP-5'), 'image_side' => 'right', 'button_target' => 'booking']),
                $this->section('doctors',
                    ['heading' => 'Echipa noastră', 'body' => 'De la consultații de rutină până la intervenții chirurgicale complexe, medicii noștri dispun de competențele necesare pentru a diagnostica și trata o gamă largă de afecțiuni oftalmologice. Fiecare specialist aduce un plus de valoare prin expertiza sa unică, asigurând astfel o abordare multidisciplinară pentru cele mai bune rezultate.'],
                    ['heading' => 'Csapatunk', 'body' => 'A rutinkonzultációktól az összetett műtétekig orvosaink képesek a szembetegségek széles skálájának diagnosztizálására és kezelésére. Minden szakember egyedi szakértelmével hozzáadott értéket képvisel, biztosítva a multidiszciplináris megközelítést a legjobb eredmények elérése érdekében.']),
            ]],

            'locations' => ['puncte-lucru', 'puncte-lucru', 'standard', ['Puncte de lucru', 'Munkapontok'], [
                $this->section('locations',
                    ['heading' => 'Punctele de lucru', 'body' => 'Opt locații în județul Mureș, cu cabinete de oftalmologie și optică. Alegeți locația cea mai apropiată.'],
                    ['heading' => 'Munkapontjaink', 'body' => 'Nyolc helyszín Maros megyében, szemészeti rendelővel és optikával. Válassza a legközelebbit.']),
            ]],

            'factory' => ['fabrica-lentile', 'fabrica-lentile', 'standard', ['Fabrica de lentile', 'Saját lencsegyár'], [
                // N-01: aligned with the Hungarian text: 8 locations, an operating room and the factory.
                $this->section('text_image',
                    ['heading' => 'Fabrica de lentile', 'body' => "Compania noastră a fost înființată în 1991. Deținem 8 puncte de lucru dotate cu cabinete de specialitate oftalmologică, o sală de operații și propria fabrică de lentile. Suntem complet independenți și putem realiza orice tip de lentilă specială.\n\nCu aproape 70 de specialiști, deservim anual peste 50.000 de pacienți."],
                    ['heading' => 'Saját lencsegyár', 'body' => "Cégünk 1991-ben alakult. Az elmúlt 35 évben folyamatosan bővültünk, mindvégig a minőségi ellátást és pácienseink igényeit helyezve a középpontba.\n\nJelenleg 8, szakrendelővel felszerelt helyiségünk mellett egy szemészeti műtővel és saját lencsegyárral is rendelkezünk. Független gyártóként a hagyományos lencséktől a legösszetettebb, személyre szabott lencsékig bármilyen egyedi igényt megvalósítunk. Közel 70 fős szakértői csapatunkkal több mint 50 000 páciens látásáról gondoskodunk."],
                    ['image_id' => $this->m('WhatsApp-Image-2025-06-16-at-09.59.24-1'), 'image_side' => 'right']),
                $this->section('rich_text',
                    ['heading' => 'Produsele noastre', 'body' => "- **Lentile bifocale:** Round Top – cu segment rotund pentru citit sau Cube – cu separare invizibilă între câmpul de vedere de aproape și cel de departe.\n- **Lentile degresive:** optimizate pentru mediul de lucru de birou.\n- **Lentile progresive personalizate:** ajustate pentru utilizarea dispozitivelor digitale. Design ergonomic, câmp vizual larg, vedere optimă în orice poziție de purtare, eliminarea distorsiunilor laterale.\n- **Lentile monofocale personalizate:** alegerea ideală a diametrului util, claritate maximă a vederii, în variantă subțiată.\n- **Lentile fotocromatice:** se întunecă la lumina soarelui.\n- **Lentile cu protecție digitală:** protejează împotriva luminii albastre emise de dispozitivele electronice, reduc oboseala oculară și durerile de cap.\n- **Stratificări speciale pentru lentile:** tratament de suprafață realizat în cuptoare speciale pentru o durabilitate crescută – antireflex, hidrofob, oleofob, antistatic și strat de protecție împotriva zgârieturilor."],
                    ['heading' => 'Termékeink', 'body' => "- **Bifokális lencsék:** kerek tetejű – kerek szegmenssel olvasáshoz, vagy kocka – láthatatlan elválasztással a közeli és távoli látómező között.\n- **Digresszív lencsék:** irodai munkakörnyezetre optimalizálva.\n- **Egyedi progresszív lencsék:** digitális eszközök használatához igazítva. Ergonomikus kialakítás, széles látómező, optimális nézet bármilyen viselési pozícióban, az oldalirányú torzítások kiküszöbölése.\n- **Személyre szabott egylátó lencsék:** ideális hasznos átmérő, maximális látásélesség, vékony változatban.\n- **Fotokróm lencsék:** napfényben besötétednek.\n- **Digitális védőlencsék:** védenek az elektronikus eszközök által kibocsátott kék fény ellen, csökkentik a szemfáradtságot és a fejfájást.\n- **Speciális lencsebevonatok:** modern felületkezelés speciális kemencékben a fokozott tartósság érdekében – tükröződésmentes, hidrofób, oleofób, antisztatikus és karcálló bevonat."]),
                $this->section('gallery', ['heading' => ''], ['heading' => ''], ['image_ids' => [$this->m('LOCALMURES-Optica-Optofarm-PRODUCTIE029-Copy'), $this->m('WhatsApp-Image-2025-06-16-at-09.59.39-1')]]),
                $this->section('rich_text',
                    ['heading' => 'Colaborare B2B – lentile de calitate direct de la producător', 'body' => "Ești optometrist, deții un cabinet oftalmologic sau un magazin de optică? Hai să construim împreună un parteneriat de încredere.\n\nSC OPTICA-OPTOFARM SRL îți oferă acces direct la propria noastră fabrică de lentile, unde producem lentile oftalmice personalizate la cele mai înalte standarde de calitate.\n\n**Ce îți oferim:**\n\n- Producție rapidă și flexibilă – lentile personalizate, inclusiv modele speciale și tratamente complexe\n- Gamă completă de lentile – monofocale, bifocale, progresive, pentru lucru la birou, fotocromatice, cu protecție digitală\n- Consultanță tehnică dedicată\n- Prețuri competitive și condiții avantajoase pentru parteneri\n- Livrare promptă oriunde în țară\n\n**De ce să colaborezi cu noi:**\n\n- Ai garanția calității de producător român cu experiență\n- Nu depinzi de importuri sau timpi lungi de așteptare\n- Primești suport real și rapid pentru orice comandă\n\nTelefon: **+40 771 048 936** · Email: **ofalentile@gmail.com**"],
                    ['heading' => 'B2B együttműködés – minőségi lencsék közvetlenül a gyártótól', 'body' => "Optometrista vagy, szemészeti klinikád vagy optikád van? Építsünk együtt egy megbízható partnerséget.\n\nAz SC OPTICA-OPTOFARM SRL közvetlen hozzáférést biztosít saját lencsegyárunkhoz, ahol a legmagasabb minőségi szabványoknak megfelelően gyártunk egyedi szemüveglencséket.\n\n**Amit kínálunk:**\n\n- Gyors és rugalmas gyártás – személyre szabott lencsék, beleértve a speciális kialakításokat és az összetett kezeléseket\n- A lencsék teljes választéka – egyfókuszú, bifokális, progresszív, irodai, fotokróm, digitális védelemmel\n- Dedikált műszaki tanácsadás\n- Versenyképes árak és előnyös feltételek partnereink számára\n- Gyors kiszállítás az ország bármely pontjára\n\n**Miért érdemes velünk együttműködni:**\n\n- Egy tapasztalt román gyártó minőségi garanciája\n- Nem függsz importtól vagy hosszú várakozási időktől\n- Valódi és gyors támogatást kapsz bármilyen megrendeléshez\n\nTelefon: **+40 771 048 936** · Email: **ofalentile@gmail.com**"]),
                $this->section('contact_form',
                    ['heading' => 'Cere o ofertă pentru colaborare', 'body' => 'Spuneți-ne ce tip de lentile vă interesează. Vă răspundem în cel mai scurt timp.'],
                    ['heading' => 'Kérjen ajánlatot együttműködésre', 'body' => 'Írja meg, milyen lencsék érdeklik. Hamarosan válaszolunk.'],
                    ['lead_type' => 'b2b']),
            ]],

            'offers' => ['reduceri-actuale', 'reduceri-actuale', 'standard', ['Reduceri actuale', 'Aktuális kedvezmények'], [
                $this->section('offers',
                    ['heading' => 'Reduceri actuale', 'body' => 'Ofertele sunt valabile în limita perioadei și a stocului disponibil.'],
                    ['heading' => 'Aktuális kedvezmények', 'body' => 'Az ajánlatok a megadott időszakban és a készlet erejéig érvényesek.']),
            ]],

            'contact' => ['contact', 'contact', 'standard', ['Contact', 'Kapcsolat'], [
                $this->section('rich_text',
                    ['heading' => 'Contact', 'body' => "Email: **jeremias@optica-optofarm.ro**\n\nTelefon programări: **0365 430 939** · **+40 265 212 304**"],
                    ['heading' => 'Kapcsolat', 'body' => "Email: **jeremias@optica-optofarm.ro**\n\nIdőpontfoglalás telefonon: **0365 430 939** · **+40 265 212 304**"]),
                $this->section('contact_form',
                    ['heading' => 'Scrieți-ne', 'body' => 'Pentru programări vă recomandăm programarea online. Pentru orice altă întrebare, folosiți formularul de mai jos.'],
                    ['heading' => 'Írjon nekünk', 'body' => 'Időpontfoglaláshoz az online foglalást ajánljuk. Minden más kérdéssel forduljon hozzánk az alábbi űrlapon.'],
                    ['lead_type' => 'contact']),
                $this->section('locations', ['heading' => 'Locații', 'body' => ''], ['heading' => 'Helyszínek', 'body' => '']),
            ]],

            'privacy' => ['privacy-policy', 'privacy-policy', 'legal', [$legal['privacy']['title']['ro'], $legal['privacy']['title']['hu']], $legalSections($legal['privacy'])],
            'cookies' => ['politica-de-cookies', 'politica-de-cookies', 'legal', [$legal['cookies']['title']['ro'], $legal['cookies']['title']['hu']], $legalSections($legal['cookies'])],
        ];
    }

    /** Old WordPress URLs: images point to the local copies, feeds and admin endpoints are gone (SEO-02). */
    private function redirects(): void
    {
        $paths = json_decode(file_get_contents(resource_path('site-import/image-paths.json')), true);
        foreach ($paths as $name => $oldPaths) {
            if (! isset($this->media[$name])) {
                continue;
            }
            $target = Media::find($this->media[$name])?->url();
            foreach ($oldPaths as $old) {
                \App\Models\Redirect::firstOrCreate(['from_path' => \App\Models\Redirect::normalize($old)], ['to_url' => parse_url($target, PHP_URL_PATH), 'status_code' => 301]);
            }
        }
        foreach (['/feed', '/comments/feed', '/hu/feed', '/hu/comments/feed', '/xmlrpc.php', '/wp-login.php', '/wp-admin', '/wp-json', '/hu/wp-json'] as $gone) {
            \App\Models\Redirect::firstOrCreate(['from_path' => $gone], ['to_url' => null, 'status_code' => 410]);
        }
    }

    private function menus(): void
    {
        $items = [
            ['services', ['Servicii', 'Szolgáltatások']],
            ['doctors', ['Medici', 'Szakorvosaink']],
            ['locations', ['Puncte de lucru', 'Munkapontok']],
            ['factory', ['Fabrica de lentile', 'Saját lencsegyár']],
            ['offers', ['Reduceri actuale', 'Kedvezmények']],
            ['contact', ['Contact', 'Kapcsolat']],
        ];
        foreach ($items as $i => [$key, [$ro, $hu]]) {
            MenuItem::create(['menu' => 'header', 'label' => ['ro' => $ro, 'hu' => $hu], 'page_id' => Page::where('key', $key)->value('id'), 'sort_order' => $i + 1]);
        }
        foreach ([['privacy', ['Politica de confidențialitate', 'Adatvédelmi irányelvek']], ['cookies', ['Politica de cookies', 'Sütikre vonatkozó szabályzat']]] as $i => [$key, [$ro, $hu]]) {
            MenuItem::create(['menu' => 'footer', 'label' => ['ro' => $ro, 'hu' => $hu], 'page_id' => Page::where('key', $key)->value('id'), 'sort_order' => $i + 1]);
        }
    }

    private function offers(): void
    {
        $content = [
            'ro' => [
                'title' => 'Campanie pentru începerea școlii – 30% reducere',
                'summary' => 'Între 15 august și 30 septembrie 2026, preșcolarii și școlarii beneficiază de 30% reducere la lentilele de ochelari participante la promoție.',
                'body' => "**Cui se adresează promoția?** Preșcolarilor și școlarilor înscriși într-o unitate de învățământ publică sau privată din România:\n\n- preșcolari\n- elevi din ciclul primar\n- elevi din ciclul gimnazial\n- elevi din ciclul liceal\n\n**Pentru ce lentile?** Lentile oftalmice cu tratament antireflex, în intervalul dioptric stabilit pentru campanie. Reducerea se aplică pentru maximum două lentile.\n\n**Ce document trebuie prezentat?** Act de identitate, certificat de naștere sau carnet de elev.\n\nParticipă lentilele fabricate de SC Optica Optofarm SRL și cele din stocul magazinelor sau al depozitului central, în intervalul dioptric stabilit. Promoția este valabilă în limita stocului disponibil.",
            ],
            'hu' => [
                'title' => '30%-os iskolakezdési akció',
                'summary' => '2026. augusztus 15. és szeptember 30. között 30% kedvezményt biztosítunk a szemüveglencsékre óvodások és iskolások számára.',
                'body' => "**Kinek szól az akció?** A romániai állami vagy magán oktatási intézménybe járó óvodásoknak és iskolásoknak:\n\n- óvodások\n- általános iskolások\n- gimnazisták\n- középiskolások\n\n**Milyen lencsékre?** A meghatározott dioptriatartományba eső, tükröződéscsökkentő (antireflex) kezeléssel ellátott szemüveglencsékre. A kedvezmény legfeljebb két lencsére érvényes.\n\n**Mit kell magaddal hozni?** Személyazonosító igazolványt, születési bizonyítványt vagy diákigazolványt.\n\nA promócióban az SC Optica Optofarm SRL által gyártott, illetve üzleteinkben vagy központi raktárunkban készleten lévő lencsék vesznek részt. Az akció a készlet erejéig érvényes.",
            ],
        ];
        Post::create([
            'type' => 'offer', 'slug_ro' => 'campanie-inceput-scoala-2026', 'slug_hu' => 'iskolakezdesi-akcio-2026',
            'draft' => $content, 'published' => $content, 'published_at' => now(),
            'image_id' => $this->m('RO-WEBPAGE-DESCRIPTION-30-1'),
            'starts_on' => '2026-08-15', 'ends_on' => '2026-09-30',
        ]);
    }
}
