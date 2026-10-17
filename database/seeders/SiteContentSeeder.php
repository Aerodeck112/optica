<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Public business data taken from https://optica-optofarm.ro (RO + /hu/) on 2026-10-17,
 * with the corrections confirmed by the beneficiary (DECISIONS.md, N-01…N-07).
 *
 * Idempotent: records are matched by their stable code and never overwritten, so edits
 * made in the admin panel survive re-running the installer or an update (UPD-05).
 * No specialists are seeded: the current site lists none individually (N-06).
 */
class SiteContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->locations() as $i => $data) {
            $location = Location::firstOrNew(['code' => $data['code']]);
            if ($location->exists) {
                continue;
            }
            $location->fill($data + ['sort_order' => $i + 1, 'is_active' => true, 'is_public' => true, 'online_booking_enabled' => true]);
            $location->published_content = $location->content;
            $location->published_at = now();
            $location->save();
        }

        foreach ($this->services() as $i => [$code, $ro, $hu]) {
            $service = Service::firstOrNew(['code' => $code]);
            if ($service->exists) {
                continue;
            }
            $content = ['ro' => ['name' => $ro[0], 'description' => $ro[1]], 'hu' => ['name' => $hu[0], 'description' => $hu[1]]];
            $service->fill([
                'category' => 'consultation',
                'content' => $content,
                'published_content' => $content,
                'published_at' => now(),
                // Durations are not published; 30 min is a placeholder to be set per specialist/location.
                'default_duration' => 30,
                'is_active' => true,
                'is_public' => true,
                'sort_order' => $i + 1,
            ])->save();
        }

        foreach ($this->settings() as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function hours(string $weekdays, ?string $saturday = null): array
    {
        [$open, $close] = explode('-', $weekdays);
        $hours = array_fill_keys([1, 2, 3, 4, 5], [[$open, $close]]);
        $hours[6] = $saturday ? [explode('-', $saturday)] : [];
        $hours[7] = [];

        return $hours;
    }

    private function location(string $code, string $city, string $cityHu, string $address, string $addressHu, string $phone, array $hours): array
    {
        return [
            'code' => $code,
            'city' => $city,
            'address' => $address,
            'phone' => $phone,
            'email' => 'jeremias@optica-optofarm.ro',
            'opening_hours' => $hours,
            'content' => [
                'ro' => ['name' => "{$city}, {$address}", 'address' => $address, 'city' => $city],
                'hu' => ['name' => "{$cityHu}, {$addressHu}", 'address' => $addressHu, 'city' => $cityHu],
            ],
        ];
    }

    private function locations(): array
    {
        return [
            $this->location('tgm-postei-3', 'Târgu Mureș', 'Marosvásárhely', 'Str. Poștei nr. 3', 'Posta utca 3. szám', '+40 265 212 304', $this->hours('08:00-21:00', '09:00-14:00')),
            $this->location('tgm-trandafirilor-53', 'Târgu Mureș', 'Marosvásárhely', 'Piața Trandafirilor nr. 53', 'Rózsák tere 53. szám', '+40 265 250 120', $this->hours('08:00-20:00')),
            // N-03: confirmed as "Piața Republicii nr. 5".
            $this->location('tgm-republicii-5', 'Târgu Mureș', 'Marosvásárhely', 'Piața Republicii nr. 5', 'Szentgyörgy tér (Köztársaság tér) 5. szám', '+40 365 430 939', $this->hours('08:00-20:00')),
            // N-04: confirmed active, including online booking.
            $this->location('tgm-1dec1918-49', 'Târgu Mureș', 'Marosvásárhely', 'Bd. 1 Decembrie 1918 nr. 49', '1918. december 1. sugárút 49. szám', '+40 265 263 351', $this->hours('08:00-20:00')),
            $this->location('tgm-gheorghe-doja-64', 'Târgu Mureș', 'Marosvásárhely', 'Str. Gheorghe Doja nr. 64-68', 'Dózsa György utca 64-68. szám', '+40 265 212 212', $this->hours('08:00-20:00')),
            $this->location('tgm-fortuna', 'Târgu Mureș', 'Marosvásárhely', 'Bd. 1 Decembrie 1918 nr. 182-184 (Fortuna)', '1918. december 1. sugárút 182-184. szám (Fortuna)', '+40 265 265 205', $this->hours('08:00-20:00')),
            $this->location('reghin-scolii-11', 'Reghin', 'Szászrégen', 'Str. Școlii nr. 11', 'Iskola utca 11. szám', '+40 265 512 042', $this->hours('08:00-18:00')),
            $this->location('sovata-principala-196', 'Sovata', 'Szováta', 'Str. Principală nr. 196', 'Fő utca 196. szám', '+40 265 577 282', $this->hours('08:00-16:00')),
        ];
    }

    private function services(): array
    {
        return [
            ['prescriptie-ochelari-lentile-contact',
                ['Prescripție ochelari și lentile de contact', 'Determinarea precisă a dioptriilor pentru corectarea defectelor de vedere (miopie, hipermetropie, astigmatism, presbiopie) și recomandarea celor mai potrivite soluții optice.'],
                ['Szemüveg- és kontaktlencse-kiírás', 'A dioptriák pontos meghatározása a látáshibák (rövidlátás, távollátás, asztigmatizmus, öregkori távollátás) korrigálására.']],
            ['screening-oftalmologic',
                ['Screening oftalmologic (copii și adulți)', 'Evaluarea rapidă și eficientă a sănătății oculare pentru depistarea timpurie a potențialelor afecțiuni sau tulburări de vedere, adaptată în funcție de vârstă.'],
                ['Szemészeti szűrővizsgálat (gyermekeknek és felnőtteknek)', 'A szem egészségének gyors és hatékony értékelése az esetleges elváltozások vagy látáshibák korai kiszűrése érdekében, az életkornak megfelelő módszerekkel.']],
            ['biomicroscop',
                ['Examinare la biomicroscop (lampă cu fantă)', 'Analiza detaliată, sub mărire, a structurilor anterioare și posterioare ale ochiului (cornee, iris, cristalin), esențială pentru diagnosticarea corectă a multor afecțiuni oculare.'],
                ['Réslámpavizsgálat', 'A szem elülső és hátulsó szöveteinek (szaruhártya, írisz, szemlencse) részletes, mikroszkópos elemzése, amely elengedhetetlen számos szemészeti betegség pontos diagnosztizálásához.']],
            ['fund-de-ochi-oct',
                ['Examinarea fundului de ochi (fotografie fund de ochi și OCT)', 'Evaluarea retinei și a nervului optic prin tehnologii avansate de scanare (OCT) și fotografiere, vitală pentru monitorizarea glaucomului, retinopatiei diabetice sau a degenerescenței maculare.'],
                ['Szemfenékvizsgálat (szemfenékfotó és OCT)', 'A retina és a látóideg értékelése fejlett szkennelési technológiával (OCT) és fényképezéssel, ami kulcsfontosságú a zöldhályog, a cukorbetegség okozta ideghártya-bántalom vagy a makuladegeneráció nyomon követésében.']],
            ['ecografie-oculara',
                ['Ecografie oculară', 'Investigație neinvazivă cu ultrasunete, utilizată pentru vizualizarea structurilor interne ale ochiului atunci când mediile oculare sunt opace (de exemplu, în caz de cataractă avansată).'],
                ['Szemultrahang', 'Fájdalommentes, ultrahangos vizsgálóeljárás a szem belső szerkezetének feltérképezésére, ha a szem közegei nem kellően átlátszóak (például előrehaladott szürkehályog esetén).']],
            ['gonioscopie',
                ['Gonioscopie', 'Examinarea unghiului de drenaj al ochiului (unghiul iridocornean) pentru a determina tipul de glaucom și pentru a stabili conduita terapeutică adecvată.'],
                ['Gonioszkópia', 'A szem elülső csarnokzugának vizsgálata, amely nélkülözhetetlen a zöldhályog típusának meghatározásához és a megfelelő kezelés kiválasztásához.']],
            ['tonometrie',
                ['Tonometrie (măsurarea tensiunii oculare)', 'Măsurarea presiunii din interiorul ochiului, o investigație rapidă și nedureroasă, fundamentală pentru prevenirea și screeningul glaucomului.'],
                ['Szemnyomásmérés', 'A szem belső nyomásának mérése gyors és fájdalommentes eljárással, amely alapvető fontosságú a zöldhályog megelőzésében és szűrésében.']],
            ['oct-unghi-pahimetrie',
                ['OCT de unghi iridocornean și pahimetrie', 'Scanarea de înaltă rezoluție a structurilor din camera anterioară a ochiului și măsurarea grosimii corneei, date esențiale pentru diagnosticul de precizie în glaucom.'],
                ['Csarnokzug-OCT és pachymetria', 'A csarnokzug nagy felbontású vizsgálata és a szaruhártya vastagságának mérése, amelyek elengedhetetlen adatok a zöldhályog precíz diagnosztizálásához.']],
            ['topografie-corneana',
                ['Topografie corneană', 'Cartografierea tridimensională a reliefului și curburii corneei, utilizată în diagnosticul keratoconusului, în adaptarea lentilelor de contact speciale sau în chirurgia refractivă.'],
                ['Korneatopográfia', 'A szaruhártya felszínének és görbületének háromdimenziós feltérképezése keratoconus diagnosztizálására, speciális kontaktlencsék illesztésére vagy lézeres szemműtétek előtt.']],
            ['camp-vizual',
                ['Examen de câmp vizual', 'Testarea sensibilității retinei și determinarea spațiului periferic pe care ochiul îl poate vedea în timp ce fixează un punct central, fiind o investigație cheie în glaucom și neurologie.'],
                ['Látótérvizsgálat', 'A retina érzékenységének tesztelése és a perifériás látómező meghatározása egy központi pont fixálása közben; kulcsfontosságú vizsgálat a zöldhályog kiszűrésére és ideggyógyászati kórképek esetén.']],
            ['examen-ortoptic-sinoptofor',
                ['Examen ortoptic la sinoptofor', 'Evaluarea detaliată a vederii binoculare, a strabismului și a capacității ochilor de a lucra împreună, realizată cu ajutorul unui aparat specializat (sinoptofor).'],
                ['Ortoptikai vizsgálat szinoptofórral', 'A kétszemes látás, a kancsalság és a két szem együttműködési képességének részletes felmérése speciális eszköz (szinoptofór) segítségével.']],
            ['management-miopie-ambliopie',
                ['Managementul miopiei și tratamentul ambliopiei', 'Programe personalizate și soluții optice moderne pentru încetinirea progresiei miopiei la copii, precum și terapii specifice pentru recuperarea acuității vizuale în caz de ambliopie și strabism.'],
                ['Rövidlátás kezelése és a tompalátás javítása', 'Személyre szabott programok és modern optikai megoldások a gyermekek rövidlátásának lassítására, valamint speciális terápiák a látásélesség helyreállítására tompalátás és kancsalság esetén.']],
        ];
    }

    private function settings(): array
    {
        return [
            // N-05: both numbers are shown for appointments.
            'site.booking_phones' => ['+40 365 430 939', '+40 265 212 304'],
            'site.contact_email' => 'jeremias@optica-optofarm.ro',
            'site.lens_factory_contact' => ['phone' => '+40 771 048 936', 'email' => 'ofalentile@gmail.com'],
            // N-01 / N-02: confirmed figures.
            'site.stats' => ['founded_year' => 1991, 'locations' => 8, 'patients_per_year' => 50000, 'specialists' => 70],
            'site.social' => ['facebook' => null, 'instagram' => null],
        ];
    }
}
