<?php

namespace Database\Seeders;

use App\Modules\Cms\Models\LegalDocument;
use Illuminate\Database\Seeder;

/**
 * Șabloane de documente juridice, pregătite cât mai complet, dar NEVALIDATE juridic.
 * Variabilele {{ company.legal_name }} etc. se completează automat din setările locației.
 * Fiecare document are o listă de puncte de verificat (review_flags) afișată în panou.
 *
 * @param  string  $status  „draft” în producție (se publică după validare), „published” în demo.
 */
class LegalDocumentsSeeder extends Seeder
{
    public string $status = 'draft';

    public function run(): void
    {
        foreach ($this->documents() as $type => $doc) {
            $record = LegalDocument::query()->firstOrNew(['type' => $type, 'version' => '0.9']);
            $record->fill([
                'title' => ['ro' => $doc['title']],
                'slug' => ['ro' => $doc['slug']],
                'body' => ['ro' => trim($doc['body'])],
                'status' => $this->status,
                'effective_at' => $this->status === 'published' ? now()->subDay() : null,
                'review_flags' => array_map(fn ($text) => ['text' => $text, 'resolved' => false], $doc['flags']),
            ]);
            $record->content_hash = $record->computeHash();
            $record->save();
        }
    }

    private function documents(): array
    {
        $company = "{{ company.legal_name }}, cu sediul în {{ company.registered_office }}, înregistrată la Registrul Comerțului sub nr. {{ company.reg_no }}, CUI {{ company.tax_id }}";

        return [
            'terms' => [
                'title' => 'Termeni și condiții', 'slug' => 'termeni-si-conditii',
                'flags' => ['Validare de către jurist a întregului document.', 'Completarea datelor firmei în Setări → Date firmă.', 'Confirmarea vârstei minime pentru rezervare (18 ani) și a regulilor de plată.'],
                'body' => <<<MD
## 1. Cine suntem
Site-ul și arena Another World Cluj sunt operate de {$company} („Another World Cluj”, „noi”). Another World Cluj este o locație din rețeaua Another World, operată în baza unui contract de franciză.

Contact: {{ contact.email_general }}, {{ contact.phone_main }}.

## 2. Ce oferim
Experiențe de realitate virtuală pentru grupuri, desfășurate în arena din Cluj-Napoca, la data și ora rezervate. Descrierea fiecărei experiențe, durata rezervării, durata efectivă a jocului și prețul sunt afișate înainte de confirmarea rezervării.

## 3. Rezervarea
1. Rezervarea se face de o persoană adultă (minimum 18 ani), care acționează în nume propriu și, după caz, în numele grupului.
2. O **rezervare** blochează intervalul ales. O **cerere de disponibilitate sau de ofertă** nu blochează arena până la confirmarea noastră.
3. Rezervarea devine confirmată atunci când primești confirmarea pe e-mail. Dacă este necesar un avans, rezervarea rămâne „în așteptare” până la plata acestuia, în termenul afișat.
4. Prețurile afișate includ TVA (dacă este cazul) și sunt cele valabile la momentul rezervării.

## 4. Plata
Metodele de plată disponibile sunt afișate în rezumatul rezervării. Nu stocăm datele cardului: plățile online sunt procesate de un procesator de plăți autorizat.

## 5. Modificare, anulare, rambursare
Se aplică [Politica de rezervare, anulare, reprogramare și rambursare](/legal/politica-rezervare).

## 6. Participarea și siguranța
Participarea presupune respectarea [Regulamentului arenei](/legal/regulament-arena), a [Informării de siguranță](/legal/informare-siguranta) și a instrucțiunilor echipei. Pentru minori se aplică [Regulile pentru participarea minorilor](/legal/participarea-minorilor). Putem refuza sau întrerupe participarea unei persoane care pune în pericol siguranța proprie sau a celorlalți.

## 7. Răspundere
Răspundem pentru furnizarea serviciilor conform legii. Nu răspundem pentru prejudiciile cauzate de nerespectarea instrucțiunilor de siguranță sau a regulamentului.

## 8. Reclamații și soluționarea litigiilor
Reclamațiile se trimit la {{ contact.email_general }}. Răspundem în cel mult 30 de zile. Consumatorii se pot adresa Autorității Naționale pentru Protecția Consumatorilor și procedurilor de soluționare alternativă a litigiilor (SAL), conform informațiilor de pe anpc.ro.

## 9. Date personale
Prelucrăm datele conform [Politicii de confidențialitate](/legal/politica-de-confidentialitate).

## 10. Legea aplicabilă
Se aplică legea română. Litigiile se soluționează pe cale amiabilă sau de instanțele competente din România.
MD,
            ],
            'privacy' => [
                'title' => 'Politica de confidențialitate', 'slug' => 'politica-de-confidentialitate',
                'flags' => ['Validare de către jurist / consultant GDPR.', 'Confirmarea termenelor de păstrare a documentelor contabile cu contabilul (Legea contabilității nr. 82/1991).', 'Lista furnizorilor efectivi (e-mail, SMS, plăți, hosting, AI) și temeiul transferurilor în afara SEE.', 'Stabilirea dacă este necesar un responsabil cu protecția datelor (DPO).'],
                'body' => <<<MD
## 1. Operatorul
Operatorul datelor este {$company}. Ne poți contacta pentru orice întrebare despre datele tale la **{{ contact.email_privacy }}**.

## 2. Ce date prelucrăm și de ce

| Scop | Date | Temei legal (GDPR) |
|---|---|---|
| Gestionarea rezervărilor și a cererilor | nume, e-mail, telefon, numărul participanților pe categorii de vârstă, mențiuni | executarea contractului — art. 6 alin. (1) lit. b |
| Facturare și evidență contabilă | date de facturare, documente fiscale | obligație legală — art. 6 alin. (1) lit. c |
| Mesaje de serviciu (confirmări, remindere) | e-mail, telefon | executarea contractului |
| Oferte și noutăți pe e-mail / SMS | e-mail, telefon | consimțământ, separat pe canal — art. 6 alin. (1) lit. a |
| Securitatea site-ului și prevenirea abuzurilor | adresă IP (stocată sub formă de amprentă criptografică), jurnale tehnice | interes legitim — art. 6 alin. (1) lit. f |
| Analiza traficului (doar cu acordul tău) | identificatori de cookie | consimțământ |
| Asistentul virtual (dacă îl folosești) | mesajele scrise în chat, cu adresele de e-mail și telefoanele mascate în jurnal | executarea demersurilor precontractuale |

Nu cerem CNP, copii ale actelor de identitate sau date medicale. Pentru minori, comunicarea se face exclusiv cu adultul responsabil; nu colectăm datele personale ale copiilor, ci doar numărul participanților pe categorii de vârstă.

Acceptarea termenilor rezervării **nu** înseamnă abonare la mesaje promoționale.

## 3. Cât timp păstrăm datele
- rezervări și cereri: pe durata relației și ulterior pentru termenul de prescripție aplicabil;
- documente financiar-contabile: conform termenelor din legislația contabilă și fiscală;
- consimțăminte și dovada lor: cât timp sunt active și ulterior pentru dovedirea conformității;
- conversațiile cu asistentul virtual: {{ ai.retention_days }} zile;
- jurnalele de mesaje: conform setărilor de retenție.

## 4. Cui transmitem datele
Furnizori care ne ajută să prestăm serviciul (găzduire, e-mail, SMS, procesator de plăți, asistent AI), doar în măsura necesară și în baza unor contracte de prelucrare; autorități, când legea o cere (ex: ANAF prin sistemul RO e-Factura); francizorul Another World, doar date agregate, dacă este cazul. Unii furnizori pot prelucra date în afara Spațiului Economic European; în acest caz folosim garanțiile prevăzute de GDPR (decizii de adecvare sau clauze contractuale standard).

## 5. Drepturile tale
Ai dreptul de acces, rectificare, ștergere, restricționare, portabilitate și opoziție, precum și dreptul de a-ți retrage oricând consimțământul. Din contul de client poți descărca datele tale și poți cere ștergerea lor. Unele date se păstrează în continuare dacă legea ne obligă (de exemplu, facturile).

Ai dreptul să depui plângere la Autoritatea Națională de Supraveghere a Prelucrării Datelor cu Caracter Personal (www.dataprotection.ro).

## 6. Securitate
Folosim conexiuni criptate, acces pe roluri, autentificare în doi pași pentru personal, jurnale de audit și copii de siguranță.
MD,
            ],
            'cookies' => [
                'title' => 'Politica de cookies', 'slug' => 'politica-cookies',
                'flags' => ['Actualizarea listei de cookie-uri după configurarea GA4 / Google Ads / Meta Pixel.', 'Validare de către jurist.'],
                'body' => <<<MD
## Ce sunt cookie-urile
Fișiere mici salvate de browser, care permit funcționarea site-ului și, cu acordul tău, analiza traficului și măsurarea campaniilor.

## Ce folosim

**Necesare (mereu active):**
- `another-world-cluj-session` — sesiunea de navigare și formularele (protecție CSRF);
- `XSRF-TOKEN` — protecție împotriva cererilor falsificate;
- `aw_consent` — memorează alegerea ta privind cookie-urile (6 luni).

**Analiză (doar cu acord):** Google Analytics 4, dacă este activat — cookie-urile `_ga`, `_ga_*`.

**Marketing (doar cu acord):** Google Ads și Meta Pixel, dacă sunt activate.

Scripturile de analiză și marketing nu se încarcă înainte de acordul tău. Folosim modul de consimțământ Google („Consent Mode”): fără acord, semnalele sunt trimise ca „refuzate”.

## Cum îți schimbi alegerea
Folosește butonul „Setări cookies” din subsolul fiecărei pagini. Retragerea acordului oprește scripturile la următoarea încărcare a paginii.
MD,
            ],
            'booking_policy' => [
                'title' => 'Politica de rezervare, anulare, reprogramare și rambursare', 'slug' => 'politica-rezervare',
                'flags' => ['Stabilirea termenelor efective de anulare/reprogramare (Setări → Rezervări).', 'Validarea de către jurist a aplicării excepției de la dreptul de retragere (art. 16 lit. l din OUG 34/2014) pentru serviciile de agrement la dată fixă.', 'Stabilirea regulilor pentru avans, neprezentare și întârzieri.'],
                'body' => <<<MD
## 1. Rezervare și confirmare
O rezervare blochează intervalul ales pentru grupul tău. Rezervarea este confirmată când primești confirmarea pe e-mail. Dacă se cere avans, rezervarea rămâne „în așteptare” până la plata acestuia, în termenul afișat; după expirarea termenului, intervalul se eliberează automat.

Cererile de ofertă (aniversări, evenimente) nu blochează arena până la confirmarea noastră.

## 2. Durata
Durata rezervării include instructajul, echiparea și pregătirea; durata efectivă a jocului este afișată separat la fiecare experiență.

## 3. Reprogramare
Poți reprograma online, din linkul primit pe e-mail, cu cel puțin **{{ booking.free_reschedule_hours }} ore** înainte de ora rezervată și de cel mult **{{ booking.max_reschedules }}** ori, în limita intervalelor libere. Pentru alte situații, contactează-ne.

## 4. Anulare
Poți anula gratuit cu cel puțin **{{ booking.free_cancellation_hours }} ore** înainte de ora rezervată. Pentru anulările ulterioare sau pentru neprezentare, avansul poate fi reținut, conform ofertei acceptate.

## 5. Rambursări
Sumele de rambursat se returnează prin aceeași metodă de plată, în cel mult 14 zile de la aprobare.

## 6. Dreptul de retragere
Serviciile de agrement prestate la o dată sau într-o perioadă anume sunt exceptate de la dreptul de retragere de 14 zile în contractele la distanță (art. 16 lit. l din OUG nr. 34/2014). Pentru aceste rezervări se aplică regulile de anulare de mai sus.

Pentru produsele care **nu** au o dată de prestare stabilită (de exemplu, vouchere cumpărate online), se aplică regulile din [Condițiile pentru vouchere](/legal/conditii-vouchere).

## 7. Anulare din partea noastră
Dacă trebuie să anulăm o rezervare (de exemplu, din motive tehnice), îți propunem o altă dată sau îți rambursăm integral sumele plătite.
MD,
            ],
            'voucher_terms' => [
                'title' => 'Condiții pentru vouchere', 'slug' => 'conditii-vouchere',
                'flags' => ['Stabilirea valabilității standard a voucherelor.', 'Validarea de către jurist a dreptului de retragere pentru voucherele vândute la distanță (fără dată de prestare).', 'Validarea de către contabil a tratamentului fiscal (voucher cu scop unic / multiplu).'],
                'body' => <<<MD
## 1. Tipuri de vouchere
- **Voucher valoric** — o sumă care poate fi folosită pentru rezervări;
- **Voucher pentru o experiență** — o experiență anume, pentru numărul de participanți indicat.

Fiecare voucher are un cod unic, iar valabilitatea și condițiile sunt trecute pe voucher.

## 2. Folosire
Spune codul la rezervare sau la recepție. Dacă voucherul permite folosirea parțială, soldul rămas poate fi folosit ulterior, în perioada de valabilitate. Un voucher nu poate fi folosit simultan pentru două rezervări.

## 3. Valabilitate
Voucherele expirate nu mai pot fi folosite, cu excepția cazurilor prevăzute de lege.

## 4. Dreptul de retragere
Pentru voucherele cumpărate la distanță (online sau telefonic), care nu au o dată de prestare stabilită, ai dreptul de a te retrage în 14 zile de la cumpărare, fără a indica motivul, dacă voucherul nu a fost folosit. Voucherele cumpărate la recepția arenei nu intră sub incidența acestei reguli.

## 5. Pierdere
Voucherul este la purtător. Te rugăm să păstrezi codul în siguranță.
MD,
            ],
            'arena_rules' => [
                'title' => 'Regulamentul arenei', 'slug' => 'regulament-arena',
                'flags' => ['Completarea cu instrucțiunile reale ale operatorului și ale francizei (echipament, spațiu, număr de jucători).', 'Validare de către operator și jurist.'],
                'body' => <<<MD
1. Respectă instrucțiunile echipei pe toată durata vizitei.
2. Folosește echipamentul doar așa cum ți s-a arătat la instructaj.
3. Nu alerga, nu sări și nu împinge alți jucători; în realitatea virtuală nu îi vezi pe ceilalți așa cum îi vezi în realitate.
4. Oprește-te imediat și anunță echipa dacă nu te simți bine.
5. Accesul sub influența alcoolului sau a altor substanțe nu este permis.
6. Mâncarea și băuturile nu sunt permise în zona de joc.
7. Daunele produse intenționat echipamentului pot fi imputate.
8. Echipa poate întrerupe jocul oricând, dacă siguranța cuiva este pusă în pericol.
MD,
            ],
            'minors' => [
                'title' => 'Reguli pentru participarea minorilor', 'slug' => 'participarea-minorilor',
                'flags' => ['Confirmarea vârstelor minime pentru fiecare joc la Cluj.', 'Stabilirea vârstei de la care minorii pot participa fără însoțitor în sală.', 'Validare de către jurist.'],
                'body' => <<<MD
1. Rezervarea pentru un grup cu minori se face de un adult (părinte, tutore sau organizator împuternicit), care își asumă responsabilitatea pentru participarea acestora.
2. Fiecare joc are o vârstă minimă recomandată, confirmată la rezervare. Echipa poate recomanda alt joc dacă cel ales nu este potrivit vârstei.
3. Adultul responsabil se asigură că minorii au citit, împreună cu el, informarea de siguranță și regulamentul arenei.
4. Nu colectăm datele personale ale copiilor; avem nevoie doar de numărul participanților pe categorii de vârstă.
5. Fotografierea sau filmarea minorilor de către noi se face doar cu acordul separat al adultului responsabil.
MD,
            ],
            'safety' => [
                'title' => 'Informare de siguranță', 'slug' => 'informare-siguranta',
                'flags' => ['Înlocuirea / completarea cu avertismentele producătorului echipamentului folosit efectiv și cu instrucțiunile francizei.', 'Validare de către operator.'],
                'body' => <<<MD
Realitatea virtuală este sigură când este folosită corect. Citește aceste informații înainte de vizită.

**Spune echipei înainte de joc dacă** ai avut crize de epilepsie sau pierderi de conștiență, ești însărcinată, ai probleme de echilibru, afecțiuni cardiace sau alte probleme de sănătate care ar putea fi agravate de efort sau de stimuli vizuali. Nu îți cerem și nu păstrăm date medicale; decizia de a juca îți aparține, iar la nevoie consultă un medic.

**Oprește-te imediat** dacă simți amețeală, greață, disconfort ocular, dureri de cap sau orice alt disconfort.

**În timpul jocului:** urmează indicațiile echipei, nu alerga și nu face mișcări bruște, nu îndepărta echipamentul fără să anunți.

Echipa urmărește jocul și poate interveni oricând pentru siguranța ta.
MD,
            ],
            'media_consent' => [
                'title' => 'Acord pentru fotografii și video', 'slug' => 'acord-foto-video',
                'flags' => ['Confirmarea modului de colectare (formular din QR-ul de pe table tent).', 'Validare de către jurist.'],
                'body' => <<<MD
Uneori fotografiem sau filmăm jocurile pentru a le arăta pe site și pe rețelele sociale.

1. Facem acest lucru **doar cu acordul tău** (sau al adultului responsabil, pentru minori), dat separat de rezervare.
2. Acordul este opțional: refuzul nu afectează participarea.
3. Poți retrage acordul oricând, scriind la {{ contact.email_privacy }}; materialele publicate ulterior nu te vor mai include, iar cele existente le eliminăm în măsura în care este posibil.
4. Nu publicăm numele persoanelor fotografiate.
MD,
            ],
        ];
    }
}
