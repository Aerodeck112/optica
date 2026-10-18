# Registrul deciziilor și presupunerilor

Format: fiecare decizie are ID, context, decizie, motiv și dacă este reversibilă. Presupunerile care trebuie confirmate de beneficiar sunt marcate **[DE CONFIRMAT]**.

## Decizii tehnice

### D-001 Framework: Laravel 13 pe PHP 8.3+, MySQL 8 / MariaDB 10.6+
- Context: ARC-01 cere PHP modular, preferabil Laravel, compatibil cu hostingul. ARC-02 exclude Node.js, Docker, Redis, WebSocket și SSH în producție.
- Decizie: Laravel 13 (versiune menținută), PHP ≥ 8.3, MySQL ≥ 8.0 sau MariaDB ≥ 10.6, charset `utf8mb4`.
- Motiv: Laravel 13 este versiunea curentă suportată, iar PHP 8.3 este disponibil în cPanel prin MultiPHP / CloudLinux PHP Selector.
- Reversibil: parțial. Dacă auditul hostingului (INS-02, cap. 13) arată doar PHP 8.2, se poate trece la Laravel 12 cu modificări minore. **[DE CONFIRMAT la auditul hostingului]**

### D-002 Interfață: Blade server-rendered + Livewire + Alpine.js, CSS compilat cu Tailwind
- Context: ARC-01 cere interfață server-rendered cu componente reactive. ARC-02 interzice Node.js în producție.
- Decizie: Blade pentru randare, Livewire pentru componentele reactive (calendar, formulare, filtre), Alpine.js pentru interacțiuni locale. Tailwind și Vite rulează **numai la build**; rezultatul compilat (`public/build`) este inclus în ZIP.
- Motiv: Livewire este un pachet PHP (licență MIT) care își livrează propriul JS prin Composer, deci nu cere Node pe server.

### D-003 Stocarea timpului: UTC în baza de date, interpretare în Europe/Bucharest
- Context: ARC-08 cere interpretarea orelor în Europe/Bucharest și teste pentru schimbarea orei.
- Decizie: `app.timezone = UTC`. Momentele (programări, jurnale) se salvează în UTC. Orarele de lucru se definesc în ora locală (zi + oră de perete) și se convertesc în UTC pentru fiecare dată concretă, cu `Europe/Bucharest` ca fus al clinicii (configurabil).
- Motiv: comparațiile de suprapunere se fac pe momente absolute, fără ambiguitate la trecerea la ora de iarnă (ora 03:00–04:00 se repetă). Un orar 09:00–17:00 rămâne 09:00–17:00 local în ambele zile de schimbare a orei.

### D-004 Concurența rezervărilor: blocare pesimistă pe specialist și resurse, în tranzacție
- Context: PRG-02, PRG-03, T02.
- Decizie: orice scriere care ocupă timp (creare, reprogramare, confirmare a unei rezervări temporare) rulează în tranzacție și:
  1. blochează rândul specialistului (`SELECT … FOR UPDATE`), apoi rândurile resurselor implicate, în ordinea crescătoare a ID-ului (fără deadlock);
  2. reverifică disponibilitatea (orar, excepții, programări existente, tampoane, timp de deplasare între locații, capacitate);
  3. scrie programarea și evenimentul de audit.
- Motiv: blocarea pe specialist serializează toate rezervările aceluiași specialist, **indiferent de locație**, ceea ce previne și suprapunerile între locații. Funcționează pe MySQL/MariaDB InnoDB fără servicii suplimentare.
- Idempotența: fiecare cerere publică poartă o cheie unică (`idempotency_key`, index unic). Retrimiterea aceluiași formular returnează aceeași programare.

### D-005 Capacitatea simultană
- Implicit 1 (LOC-03). Pentru o ofertă locație–specialist–serviciu cu capacitate > 1, o programare nouă este acceptată dacă numărul **maxim** de programări suprapuse în orice moment din intervalul ei, plus ea însăși, nu depășește capacitatea. Calculul se face prin baleiere pe evenimentele de început/sfârșit, deci funcționează și când serviciile au durate diferite (PRG-03).
- Suprapunerea unui specialist între **locații diferite** nu este permisă niciodată. Între locații se aplică și timpul de deplasare configurat.

### D-006 Cozi și sarcini programate prin cron cPanel
- Decizie: driver de coadă `database`. Un singur cron pe minut: `php artisan schedule:run`. Planificatorul pornește `queue:work --stop-when-empty --max-time=50` cu `withoutOverlapping()` (blocare în tabelul `cache_locks`). Se înregistrează ultima rulare a cronului; lipsa ei generează avertizare în panoul de diagnostic (OPS-02).
- Alternativă documentată în DEPLOYMENT.md, pentru cazul în care hostingul interzice `proc_open`.

### D-007 Model de permisiuni propriu, cu refuz implicit
- Context: ACC-01 și ACC-02 cer permisiuni granulare pe acțiune, limitate pe locații și pe relația cu pacientul. Rolurile trebuie să poată fi personalizate.
- Decizie: tabele proprii `roles`, `permissions`, `permission_role`, `role_user`, `location_user`. Permisiunile au forma `modul.acțiune` (ex. `appointments.create`). Domeniul pe locații vine din `location_user`; un rol poate avea `all_locations = true`. Verificarea se face prin Policies și Gates la fiecare operațiune, fișier și export. Detalii în PERMISSIONS.md.
- Motiv: pachetele uzuale tratează rolurile global sau pe „echipă” și nu acoperă combinația rol + set de locații + relația cu pacientul fără adaptări. Un model propriu, mic și testat, este mai ușor de auditat.

### D-008 Traduceri: fișiere standard + suprascrieri în baza de date
- Context: LNG, TRD-01–05.
- Decizie: textele interfeței au chei stabile, cu valori standard în `lang/ro` și `lang/hu` (livrate în ZIP). Corecturile beneficiarului se salvează în `translation_overrides` și au prioritate. Actualizările ZIP înlocuiesc numai fișierele standard, deci corecturile rămân. Conținutul administrabil (pagini, servicii, locații etc.) este stocat bilingv în coloane JSON `{ro, hu}`.
- Variabilele `{patient_name}` etc. sunt validate la salvare: traducerea trebuie să conțină exact aceleași variabile ca textul standard.

### D-009 Semnarea pachetelor de actualizare: Ed25519 (libsodium)
- Context: UPD-01 și UPD-02.
- Decizie: fiecare pachet conține `manifest.json` (versiune sursă minimă, versiune țintă, SHA-256 pentru fiecare fișier) și `manifest.sig`, o semnătură Ed25519 detașată. În aplicație se livrează numai cheia publică. Cheia privată se păstrează offline, la furnizor, și nu se publică în repository.
- Extensia `sodium` face parte din PHP ≥ 7.2 și este verificată de instalator.

### D-010 Actualizarea calendarului: polling Livewire
- Context: ARC-05.
- Decizie: polling configurabil, implicit 15 s, cu afișarea orei ultimei actualizări și a stării conexiunii. Nu se folosesc WebSocket-uri.

### D-011 Rutare și limbă
- Site public: RO fără prefix (`/servicii/`), HU cu prefix `/hu/` (`/hu/servicii/`), la fel ca site-ul actual (TranslatePress). Astfel URL-urile existente se păstrează (SEO-02).
- CRM `/crm` și admin `/admin`: limba vine din profilul angajatului (LNG-02), nu din URL.
- Programare: `/programare` și `/hu/programare`.

### D-012 Adrese canonice fără „/” final
- Context: site-ul actual folosește `/servicii/`; Laravel generează `/servicii`. Două adrese pentru aceeași pagină dublează indexarea.
- Decizie: adresa canonică nu are „/” la final. `RemoveTrailingSlash` răspunde cu 301 la orice cerere GET cu „/” final (inclusiv parametrii), pe orice server, nu doar pe Apache. Linkurile vechi și cele din Google continuă să funcționeze.

### D-013 Conținutul paginilor: secțiuni structurate, text simplu fără HTML
- Context: CMS-01, SEC-02. Un editor WYSIWYG cu HTML liber permite injectarea de cod și strică designul.
- Decizie: paginile sunt liste ordonate de secțiuni tipizate (`App\Domain\Cms\Sections`). Textele se scriu simplu (rând gol = paragraf, „- ” = listă, `**text**` = îngroșat) și sunt escapate la afișare (`SafeText`). La salvare, ciorna este reconstruită după definiția secțiunii: cheile necunoscute dispar, imaginile inexistente se elimină.
- Concurență: fiecare salvare verifică `lock_version`; dacă altcineva a salvat între timp, modificarea este refuzată și editorul poate încărca varianta salvată.
- Schimbarea adresei unei pagini publicate creează automat o redirecționare 301 de la adresa veche.

### D-014 Imagini
- Toate imaginile încărcate sunt re-encodate în WebP (480/960/1600 px), ceea ce elimină metadatele (EXIF, locație) și orice conținut executabil. Se stochează în `public/uploads` (fără symlink, compatibil cPanel). O imagine folosită nu poate fi ștearsă.

### D-015 Datele clinice criptate și separate de jurnal
- Fișele, amendamentele, prescripțiile și instantaneul dosarelor unite folosesc criptarea Laravel (AES-256, cheia `APP_KEY`). O copie a bazei de date fără cheie nu expune conținut clinic. Consecință: cheia trebuie inclusă în backupul configurației (DEPLOYMENT.md), altfel datele nu pot fi recuperate.
- Căutarea după conținut clinic nu este posibilă direct în SQL; rapoartele clinice vor decripta în aplicație, pe loturi.
- Jurnalul de audit nu conține valori clinice, doar acțiunea, autorul, ora și, la amendamente, numele câmpurilor modificate.

### D-016 Șabloane de fișă validate de personal medical
- Șablonul propus (`ClinicalTemplateSeeder`) este construit din lista de câmpuri MED-01; unitățile, intervalele și pașii sunt o propunere tehnică. El rămâne **nevalidat** și nu poate fi folosit până când o persoană cu dreptul `medical_records.templates` îl verifică și îl validează. Dreptul nu este acordat implicit niciunui rol, nici superadministratorului.
- Un șablon validat nu se mai modifică: o schimbare creează o versiune nouă; fișele existente rămân legate de versiunea cu care au fost scrise.

### D-017 Unirea dosarelor duplicate
- Se face doar manual, de un utilizator cu `patients.merge`, după compararea celor două dosare și confirmare. Toate referințele (programări, vizite, fișe, prescripții, documente, consimțăminte, solicitări, sarcini, relații de familie) trec în dosarul păstrat; duplicatul rămâne arhivat și trimite la acesta. Un instantaneu criptat al datelor duplicatului se păstrează în `patient_merges`.

### D-018 Documente private
- Fișierele pacienților se păstrează în `storage/app/private` (în afara directorului public), sub nume aleatorii fără extensie. Tipul se verifică după conținut (PDF, JPEG, PNG, WebP; maximum 20 MB). Descărcarea trece printr-un controller care verifică dreptul, jurnalizează accesul și trimite `X-Content-Type-Options: nosniff`. Ștergerea ascunde documentul; fișierul rămâne până la validarea regulilor de retenție (SEC-05).

### D-019 Financiar operațional ca registru
- Sumele se păstrează în bani întregi (`*_cents`), nu în zecimale cu virgulă mobilă; totalurile și soldurile sunt exacte.
- Valoarea (servicii efectuate, comenzi neanulate) și încasările (plăți minus restituiri) sunt separate. Avansul este o plată obișnuită marcată „avans”, deci achitarea soldului nu îl numără din nou. Fiecare plată are legătură explicită cu comanda sau serviciul plătit.
- O plată nu poate depăși soldul, o restituire nu poate depăși suma încasată; operațiunile pe aceeași comandă sunt serializate prin blocare. O greșeală se anulează cu motiv (rândul rămâne în jurnal) și se reintroduce corect.
- Aplicația nu emite documente fiscale și nu transmite e-Factura (FIN-03); documentul de predare și jurnalul de încasări spun explicit acest lucru. Un eventual conector fiscal va fi un modul separat, documentat împreună cu costurile.

### D-020 Furnizori de comunicare și expedierea prin cron
- Adaptoare implementate: SMTP (email), Twilio (SMS) și WhatsApp Business Cloud API de la Meta (WhatsApp). Sunt API-uri publice, documentate, cu confirmări de livrare semnate (WhatsApp: HMAC-SHA256 cu App Secret; Twilio: HMAC-SHA1 cu Auth Token). Un alt furnizor de SMS (de ex. unul românesc) se adaugă ca adaptor nou, după audit, fără schimbări în restul aplicației.
- Nu există un „driver de test” care să marcheze mesaje ca trimise: fără credențiale, canalul este „neconfigurat” și mesajul rămâne „netrimis”, cu motivul afișat.
- Mesajele se trimit din comanda `messages:send`, rulată de cron în fiecare minut, cu revendicare atomică a fiecărui mesaj (fără Redis sau worker permanent). Erorile temporare se reîncearcă de cel mult 5 ori (1, 5, 15, 60 minute); un timeout după trimiterea cererii marchează mesajul „rezultat neconfirmat” și nu se retrimite automat.
- Linkul de gestionare din mesaje are nevoie de token: acesta se păstrează criptat în programare, iar căutarea folosește în continuare doar hash-ul.
- Email: SMTP nu oferă confirmare de livrare, deci emailurile rămân „acceptat de furnizor”; deschiderile nu sunt măsurate.

### D-021 Reguli de calcul în rapoarte
- „Programări” = programări distincte, fără rezervările temporare (`held`); data folosită (consultație sau creare) se alege explicit.
- Rata anulărilor = anulate / toate programările din set. Rata neprezentărilor = neprezentări / programările scadente (sosit, în consultație, finalizat, neprezentat), ca o programare viitoare să nu scadă rata.
- Reprogramări = evenimente de mutare datate în perioadă, separat de programări și pacienți.
- Ocupare = minute ale programărilor în statusurile care ocupă timp (în așteptare, confirmată, sosit, în consultație, finalizată, neprezentat) / minute de lucru calculate de același motor ca programarea online (orare, pauze, concedii, excepții, ora de vară), înmulțite cu capacitatea maximă configurată.
- Pacient nou = fără o programare anterioară neanulată, în nicio locație.
- Financiar: valoarea serviciilor, valoarea comenzilor predate, încasări, avansuri (incluse o singură dată), restituiri și soldurile de încasat sunt afișate separat.
- Sursele lipsă rămân vizibile; nicio conversie nu este atribuită unei campanii fără legătură înregistrată.
- Calculele se fac în aplicație pentru grupările pe zi și oră (ora clinicii), fără a depinde de tabelele de fus orar ale MySQL, care lipsesc adesea pe găzduirea partajată.

### D-022 Editorul vizual de traduceri
- Modul de traducere se pornește per sesiune, doar de utilizatorii cu `translations.edit`, din CRM sau de pe site. Serverul verifică dreptul la fiecare cerere; un indicator rămas în sesiune nu ajunge la cineva fără drept.
- Textele nu sunt modificate în HTML (fără marcaje în conținut): serverul atașează paginii lista cheilor afișate, iar pagina marchează elementele după text. Astfel, datele salvate, emailurile, PDF-urile și exporturile nu pot fi „poluate”.
- Textele cu variabile (ex. „Bună ziua, :name”) intră în listă doar ca șablon și se recunosc pe pagină după model; numele și alte date nu ajung în dicționar.
- Un clic pe un element marcat deschide editorul și nu execută acțiunea elementului (buton, link, formular).
- Modificarea unei chei se aplică în toate locurile unde este folosită; editorul arată cheile diferite care au același text, pentru a o alege pe cea corectă.
- Paginile în care apare un text se înregistrează doar în modul de traducere (cel mult o dată la 10 minute pe pagină) și alimentează filtrul „Pagina” din centru.
- Importul din CSV/XLSX creează doar ciorne, cu validarea variabilelor; publicarea rămâne un pas separat, cu `translations.publish`.
- Observațiile medicale și alte texte introduse de personal nu sunt texte de interfață și nu apar în editor sau în centru.

### D-023 Instalatorul web
- Context: INS-01, INS-02; pe cPanel nu există SSH, Composer sau npm.
- Prima cerere scrie `.env` din `.env.example`, cu cheie nouă și sesiuni/cache pe fișiere, ca instalatorul să funcționeze înainte de baza de date. Instalatorul folosește formulare simple, nu Livewire.
- Protecție: un token aleator scris pe server (citit prin File Manager), schimbat de fiecare instalare; fără token nu se poate face niciun pas. După finalizare, fișierul de blocare face `/install` inexistent.
- Baza de date trebuie să fie goală (sau să conțină doar o instalare întreruptă a platformei); o bază cu alte tabele este refuzată, ca nimic să nu fie suprascris.
- Varianta pentru document root fix: `.htaccess` în rădăcină, care trimite toate cererile în `public/` (verificată pe Apache 2.4). Copierea lui `public/` în altă parte a fost respinsă: actualizările nu ar mai ajunge la fișierele publice.

### D-024 Backup în PHP, criptat
- Context: UPD-04, SEC-06; pe găzduirea partajată `exec`/`mysqldump` sunt de obicei interzise.
- Exportul bazei de date este scris de aplicație, cu o instrucțiune pe linie (valorile cu linii noi sunt escapate), astfel încât restaurarea nu interpretează SQL. Ultima linie marchează un export complet; un export trunchiat nu se restaurează niciodată.
- Restaurarea șterge întâi toate tabelele, ca o migrare eșuată să nu lase tabele noi.
- Piesele se criptează cu XChaCha20-Poly1305 (libsodium, autentificat), cu o cheie derivată din `APP_KEY`: cine poate citi datele clinice poate citi backupul, nimeni altcineva. Pierderea `APP_KEY` face backupurile ilizibile, la fel ca datele clinice (DEPLOYMENT.md).
- Tabelele `sessions`, `cache`, `cache_locks` și `jobs` se salvează fără conținut.

### D-025 Actualizări în pași, cu rollback automat
- Fiecare cerere execută un singur pas (fișierele în loturi de 400), ca să nu depășească limitele de timp ale găzduirii partajate. Jurnalul pe disc (nu baza de date) este sursa de adevăr, pentru că baza de date poate fi restaurată în timpul procesului.
- Revenirea bazei de date este restaurarea backupului făcut la începutul actualizării, nu metodele `down()` ale migrărilor. În mentenanță nu se pot introduce date, deci restaurarea nu pierde nimic.
- Un fișier nou este notat în jurnal înainte de a fi scris, ca rollbackul să-l poată șterge chiar dacă procesul se oprește în mijlocul lotului.
- Permisiunile introduse de o versiune nouă se adaugă rolurilor implicite la actualizare; modificările făcute de administrator rolurilor rămân.
- Cheia privată de semnare nu există în repository. `resources/update-keys.txt` este gol până când furnizorul își generează perechea (`optica:update-keygen`); până atunci actualizările apar „neconfigurate”.

### D-026 Importul de date
- Identificatorii externi se păstrează cu prefixul sursei (`evolvo:…`), ca două sisteme să nu se amestece.
- Un import repetat completează doar câmpurile goale ale înregistrărilor create de importuri anterioare; nu suprascrie modificările personalului.
- Duplicatele se raportează, nu se unesc: același CNP leagă identificatorul de pacientul existent; același nume cu același telefon sau aceeași dată de naștere marchează un posibil duplicat pentru decizie manuală (PAC-02).
- Simularea este obligatorie înainte de import. Programările importate au notificările dezactivate (trecute: întotdeauna; viitoare: dacă nu se alege altfel), ca să nu plece remindere retroactive (MIG-05). Datele clinice nu se importă prin acest ecran.

## Neconcordanțe constatate în surse – rezolvate de beneficiar (17.10.2026)

| # | Constatare | Decizia beneficiarului | Aplicare |
|---|---|---|---|
| N-01 | 8 locații (Acasă, footer) față de „10 puncte de lucru” (Fabrica de lentile) | Sunt valabile locațiile din footer (8). | `SiteContentSeeder` importă cele 8 locații. Pe pagina Fabrica de lentile, textul RO a fost aliniat la explicația din HU: 8 puncte de lucru, o sală de operații și fabrica proprie. |
| N-02 | 40.000 clienți / 50.000 pacienți pe an / ~70 specialiști | Se folosesc **50.000** și **70**. | Cifrele apar în textul paginilor (editabil din CMS); contorul „40000” a fost înlocuit cu 50.000. |
| N-03 | „Str. Republicii nr. 5” față de „Piața Republicii 5” | **Piața Republicii nr. 5**. | Adresa RO corectată. Varianta HU („Szentgyörgy tér (Köztársaság tér) 5. szám”) e propusă după textul HU existent și poate fi editată din admin. |
| N-04 | Bd. 1 Decembrie 1918 nr. 49 inactivă online în Evolvo | Locația este **activă**, inclusiv pentru programare online. | `online_booking_enabled = true`. |
| N-05 | Două telefoane de programare | Se afișează **ambele**: 0365 430 939 și +40 265 212 304. | Setarea `site.booking_phones`. |
| N-06 | Pagina Medici nu listează medicii | Se publică **doar ce există pe site** (niciun profil individual). Medicii se adaugă, se editează și se șterg din admin. | Nu se importă specialiști. Modulul Admin → Specialiști are CRUD complet. |
| N-07 | Programul magazinelor față de orarele de consultații | Programul magazinelor se importă de pe site. Orarele de consultații se configurează în CRM. | `locations.opening_hours`. |
| — | Exporturile Evolvo | Se fac **la final**. | Importul (MIG-01…05) rămâne ultimul pas. |

## De verificat de beneficiar

| # | Constatare | Ce am făcut | Ce trebuie verificat |
|---|---|---|---|
| N-08 | Paginile legale (confidențialitate, cookie-uri) există pe site doar în română, inclusiv pe /hu/. Paragraful „Știai că…” de pe pagina Medici nu are variantă HU. | Paginile legale HU afișează textul românesc cu o notă că versiunea maghiară nu este disponibilă. Paragraful „Știai că…” a fost tradus în maghiară. | Traducerea maghiară a paragrafului; dacă se dorește o versiune HU a textelor legale (SEC-05). |

## Presupuneri
- P-01: Limbile platformei sunt RO și HU. Evolvo are și „Informații medic EN”, dar caietul nu cere engleza; EN nu se implementează.
- P-02: Modul de confirmare implicit pentru programările online este „prin recepție” (în așteptare). Se poate trece pe „automat” per locație.
- P-03: O rezervare temporară (în timpul completării formularului public) blochează slotul timp de 10 minute (configurabil). O solicitare în așteptare blochează slotul până la decizia recepției sau până la expirare (implicit 24 h, configurabil).
- P-04: Durata serviciilor nu este publicată. Valoarea inițială este 30 de minute (placeholder) și se ajustează pe fiecare serviciu și pe fiecare combinație locație–specialist din admin.
