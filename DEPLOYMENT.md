# Instalare și operare

Ghid pentru instalarea pe găzduire cPanel, cron, backup și restaurare, actualizări semnate, recuperare și import. Toate ecranele instalatorului și ale actualizatorului sunt în română și maghiară.

## Cerințe server (de verificat la auditul hostingului)
- PHP **8.3 sau mai nou** (în cPanel: *MultiPHP Manager* sau *Select PHP Version*).
- Extensii PHP: `pdo_mysql`, `mbstring`, `intl`, `sodium`, `zip`, `gd`, `fileinfo`, `openssl`, `ctype`, `curl`, `dom`, `tokenizer`, `xml`. Instalatorul le verifică pe toate.
- MySQL 8.0+ sau MariaDB 10.6+, cu InnoDB și `utf8mb4`.
- Cron cPanel cu rulare la fiecare minut.
- `upload_max_filesize` și `post_max_size` de cel puțin 64 MB pentru pachetele de actualizare (ideal 100 MB; pachetul complet are ~50 MB).
- Spațiu: minimum 1 GB, plus documentele și backupurile (fiecare backup conține baza de date și fișierele încărcate).
- **Nu sunt necesare** Node.js, Composer, SSH, Docker, Redis sau WebSocket. Nu se folosesc `exec`, `mysqldump` sau symlinkuri.

## Pachetele livrate
| Fișier | Rol |
|---|---|
| `optica-<versiune>-install.zip` | aplicația completă: cod, `vendor/` (fără pachete de dezvoltare), CSS/JS compilate, `release.json` (sumele SHA-256 ale fișierelor) |
| `optica-<versiune>-update.zip` | aceleași fișiere sub `files/`, cu `manifest.json` (versiune, versiune sursă minimă, PHP minim, changelog RO/HU) și `manifest.sig` (semnătură Ed25519) |

## Instalarea pe cPanel (INS-01, INS-02)
1. **Baza de date:** cPanel → *MySQL Databases* → creați o bază de date (de ex. `cont_optica`) și un utilizator cu parolă puternică; adăugați utilizatorul la bază cu *ALL PRIVILEGES*. Baza trebuie să fie goală.
2. **Fișierele:** cPanel → *File Manager* → creați directorul `/home/UTILIZATOR/optica`, încărcați `optica-<versiune>-install.zip` în el și alegeți *Extract*.
3. **Domeniul:** cPanel → *Domains* → domeniul (sau un subdomeniu de test) → *Document Root* = `/home/UTILIZATOR/optica/public`. Dacă document rootul nu se poate schimba, vezi secțiunea „Document root” de mai jos.
4. **PHP:** cPanel → *MultiPHP Manager* → PHP 8.3 pentru domeniu; în *MultiPHP INI Editor* creșteți `upload_max_filesize` și `post_max_size` la 100M.
5. **Instalatorul:** deschideți `https://domeniu/`. Prima cerere scrie un fișier `.env` cu o cheie nouă (`APP_KEY`) și vă trimite la `/install`. Codul de instalare se află în `storage/app/install-token.txt` (îl citiți din *File Manager*).
6. **Pașii instalatorului:** verificarea serverului → baza de date (conexiunea, versiunea, drepturile; o bază cu alte tabele este refuzată) → crearea structurii, a rolurilor, a conținutului site-ului actual, a șabloanelor de mesaje RO/HU (fără date demonstrative) → administratorul (parolă de minimum 12 caractere cu litere mari, mici și cifre; 2FA se configurează la prima autentificare) → adresa site-ului, fusul orar, SMTP (opțional; butonul de verificare testează doar conectarea, fără a trimite mesaje) → finalizare.
7. **Finalizare:** instalatorul afișează linia de cron și **tokenul de recuperare** (o singură dată). Se scrie `storage/app/installed.lock`, codul de instalare se șterge, iar `/install` răspunde de acum cu 404.
8. Adăugați linia de cron (mai jos), autentificați-vă la `/crm/login`, configurați 2FA, apoi **Sistem → Backup → Backup zilnic**.

Verificat în acest mediu: pachetul de instalare a fost dezarhivat într-un director gol și instalat prin browser pe Apache și pe serverul PHP integrat, cu baza de date goală (vezi PROGRESS.md).

## Site de test: test.optica-optofarm.ro
Instalarea de test are același cod ca site-ul live, dar:
- nu apare în Google (`robots.txt` blochează tot, fiecare răspuns are `X-Robots-Tag: noindex`);
- fiecare pagină a site-ului are o bandă „Site de test”, iar CRM-ul are eticheta „Mediu de test”;
- **niciun mesaj nu ajunge la pacienți**: emailurile, SMS-urile și mesajele WhatsApp pleacă doar către adresele și numerele de test trecute la instalare (`OPTICA_TEST_RECIPIENTS`); celelalte apar în jurnal ca „sărit – site de test”;
- poate primi date demonstrative fictive (pacienți, programări, comenzi marcate DEMO), fără conturi de acces cu parole cunoscute.

Pași:
1. cPanel → *Domains* → *Create A New Domain* → `test.optica-optofarm.ro`, cu document root `/home/UTILIZATOR/optica-test/public` (debifați *Share document root*). Activați SSL (cPanel → *SSL/TLS Status* → *Run AutoSSL*).
2. cPanel → *MySQL Databases*: bază și utilizator separate pentru test (de ex. `cont_optica_test`). Nu folosiți baza site-ului live.
3. Încărcați `optica-<versiune>-install.zip` în `/home/UTILIZATOR/optica-test` și dezarhivați-l.
4. Deschideți `https://test.optica-optofarm.ro` și urmați instalatorul. La pasul „Domeniu, fus orar, email”, opțiunea **„Aceasta este o instalare de test”** este deja bifată pentru un domeniu care începe cu `test.`; completați adresele și numerele de test și, dacă doriți, bifați datele demonstrative.
5. Adăugați linia de cron afișată la final (cu calea `optica-test`).
6. Opțional, pentru ca doar echipa și beneficiarul să vadă site-ul: cPanel → *Directory Privacy* pe `optica-test/public`, cu un utilizator și o parolă comune pentru test.
7. Versiunile noi se aplică din CRM → Sistem → Actualizări (pachetele sunt semnate cu cheia furnizorului, deja de încredere în pachet).

## Trecerea pe live (după aprobare)
1. Backup al site-ului actual (WordPress): cPanel → *Backup* (fișiere + bază de date).
2. Instalare **separată** pe live, din același pachet aprobat: director nou (de ex. `/home/UTILIZATOR/optica`), bază de date nouă, **fără** opțiunea de test și fără date demonstrative.
3. Configurare: medicii, orarele, conturile de email/SMS/WhatsApp, utilizatorii; textele și traducerile corectate pe test se mută prin export/import din Centrul de traduceri.
4. Importul final din Evolvo (Sistem → Import date), cu simulare și reconciliere.
5. Comutarea domeniului: cPanel → *Domains* → `optica-optofarm.ro` → document root = `/home/UTILIZATOR/optica/public`. Adresele vechi ale site-ului sunt păstrate sau redirecționate (SEO).
6. Verificare pe live: pagini, programare online, autentificare CRM, Diagnostic (cron, backup, email). Site-ul WordPress rămâne pe server, neatins, până la confirmare; revenirea înseamnă doar schimbarea înapoi a document rootului.

## Document root
**Varianta recomandată:** domeniul indică spre `public/` (pasul 3). Codul, `.env`, `storage/` și backupurile rămân în afara zonei publice (SEC-02).

**Varianta pentru document root fix** (de exemplu, domeniul principal în `public_html`, fără posibilitate de schimbare): dezarhivați aplicația direct în `public_html`. Fișierul `.htaccess` din rădăcina pachetului trimite fiecare cerere în `public/` și blochează `.env`, `artisan`, `composer.*`, `release.json` și fișierele `.md`; `storage/` nu este accesibil, pentru că nicio cerere nu ajunge în afara lui `public/`. Actualizările funcționează la fel, pentru că structura rămâne aceeași. Verificați după instalare că `https://domeniu/.env` răspunde cu 403 sau 404. Instalatorul recunoaște această variantă și o marchează cu avertisment. Ambele variante au fost verificate pe Apache 2.4 cu mod_php (rezultate în PROGRESS.md).

## Linia de cron (unică)
```
* * * * * /usr/local/bin/php /home/UTILIZATOR/optica/artisan schedule:run >> /dev/null 2>&1
```
Calea exactă către PHP diferă între servere; instalatorul o afișează pe cea a serverului. Cronul rulează:
- heartbeat-ul (Diagnosticul avertizează dacă lipsește de peste 5 minute);
- expirarea rezervărilor temporare și a cererilor neconfirmate;
- coada (emailuri, exporturi) în loturi de maximum 50 de secunde, fără rulări suprapuse;
- `messages:send` (remindere, campanii, coada de mesaje);
- rapoartele periodice și ștergerea exporturilor vechi;
- `optica:backup --scheduled` (backupul zilnic, dacă este activat).

În timpul unei actualizări (mod mentenanță) cronul nu pornește sarcini: scrierile sunt blocate.

## Backup și restaurare (ghid)
- **Ce conține:** baza de date (export PHP, fără `mysqldump`), `public/uploads/` și `storage/app/private/` (documentele pacienților), fără backupurile anterioare, exporturile temporare și fișierele de import.
- **Criptare (SEC-06):** fiecare parte este criptată (XChaCha20-Poly1305, autentificat) cu o cheie derivată din `APP_KEY`; pe disc și în copiile descărcate nu rămâne nimic în clar. Fără `APP_KEY`, backupul nu poate fi citit.
- **Verificare:** fiecare parte are suma SHA-256 în `manifest.json`; exportul bazei de date se termină cu un marcaj de completitudine. Un backup incomplet sau modificat este marcat și nu poate fi restaurat.
- **Unde:** `storage/app/private/backups/<data>-<tip>-<cod>/`, în afara zonei publice. Descărcați periodic o copie (Sistem → Backup → Descarcă); descărcarea este jurnalizată.
- **Automat:** Sistem → Backup → Backup zilnic (ora clinicii). Se păstrează ultimele 7 backupuri regulate (`BACKUP_KEEP`) și ultimele 3 de fiecare alt tip.
- **Restaurare:** Sistem → Backup → Restaurează, cu confirmarea scrisă „RESTAUREAZĂ”. Înainte se face automat un backup al stării curente („Înainte de restaurare”), deci restaurarea poate fi anulată. Tot ce s-a introdus după data backupului se pierde din aplicație: alegeți momentul cu grijă.
- **Fără aplicație:** pentru o actualizare întreruptă, folosiți `/recovery.php` (mai jos).
- **Pe alt server (dezastru):** instalați aceeași versiune, puneți în `.env` cheia `APP_KEY` a instalării vechi, copiați directorul backupului descărcat (dezarhivat) în `storage/app/private/backups/`, apoi Sistem → Backup → Restaurează. Pentru o inspecție manuală: `php artisan optica:backup-decrypt database.sql.gz.enc database.sql.gz --key=base64:...`.
- **Cheia de criptare:** datele clinice din backup se pot citi numai cu `APP_KEY` (secțiunea următoare). Păstrați cheia separat de backup.

## Actualizări (UPD-01…06)
1. Furnizorul livrează `optica-<versiune>-update.zip`.
2. **Sistem → Actualizări → Verifică pachetul.** Se verifică: semnătura Ed25519 cu o cheie de încredere, versiunea (mai nouă decât cea instalată, compatibilă cu versiunea sursă), PHP minim, spațiul liber, numărul și dimensiunea fișierelor, căile (fără `..`, căi absolute sau symlinkuri), corespondența exactă cu manifestul și suma fiecărui fișier. Fișierele protejate (`.env`, `storage/`, `public/uploads/`, `bootstrap/cache/`) nu pot fi modificate de niciun pachet. Pachetele respinse rămân în istoric, cu motivul.
3. Se afișează versiunile, changelogul RO/HU și numărul de fișiere care se schimbă; actualizarea pornește doar la confirmare.
4. **Pașii:** backup verificat (baza de date + fișierele care se înlocuiesc sau se șterg) → mod mentenanță (vizitatorii primesc 503; administratorul care a pornit actualizarea continuă) → fișierele, în loturi, fiecare scris atomic → migrările → permisiunile noi pentru rolurile implicite → verificări (sume, versiune, migrări aplicate) → ieșire din mentenanță. Fiecare pas este scris în `storage/app/private/updates/current.json` înainte și după execuție.
5. **La eroare:** fișierele înlocuite revin, fișierele noi se șterg, iar baza de date se restaurează din backup dacă actualizarea ajunsese la migrări. În mentenanță nu s-au putut introduce date noi, deci restaurarea nu pierde nimic.
6. **Întreruperi:** dacă pagina se închide, actualizarea se poate relua (pașii sunt repetabili) sau anula din aceeași pagină; dacă aplicația nu mai pornește, din `/recovery.php`.
7. Linie de comandă (dacă hostingul are *Terminal*): `php artisan optica:update pachet.zip`, `--resume`, `--rollback`.

**Strategia de revenire a bazei de date:** pentru fiecare migrare, revenirea este restaurarea backupului verificat făcut la începutul actualizării (nu se folosesc metodele `down()` pe date de producție). Câmpul `migration_notes` din manifest descrie, per versiune, migrările care schimbă date existente; changelogul le menționează.

## Recuperare (`/recovery.php`)
Pagină independentă de aplicație (doar PHP și datele din `.env`), RO/HU. Cere tokenul de recuperare afișat la instalare (sau generat din Sistem → Actualizări / `php artisan optica:recovery-token`); serverul păstrează doar hash-ul tokenului. După 5 încercări greșite, adresa IP este blocată 15 minute. Permite: afișarea stării ultimei actualizări și a jurnalului, **restaurarea stării dinaintea actualizării** (fișiere și bază de date) și **ieșirea din modul mentenanță**. Dacă aplicația este instalată în altă cale decât `public/..`, modificați variabila `$base` de la începutul fișierului.

## Semnarea pachetelor (furnizor)
- Perechea de chei se generează o singură dată, pe calculatorul de build: `php artisan optica:update-keygen /cale/sigura/optica-update.key`. Comanda refuză să scrie cheia secretă în directorul aplicației.
- **Cheia secretă** rămâne offline, la furnizor; nu se pune în repository, în pachete sau pe server. **Cheia publică** (afișată de comandă) se adaugă în `resources/update-keys.txt`, care se livrează cu aplicația. O schimbare de cheie se face printr-o actualizare semnată cu cheia veche care adaugă cheia nouă. Variabila `UPDATE_PUBLIC_KEYS` din `.env` poate adăuga chei, separate prin virgulă.
- Fără nicio cheie publică, pagina Actualizări afișează „Neconfigurat” și nu acceptă pachete.
- **Construirea unei versiuni:**
  ```
  composer install --no-dev --optimize-autoloader   # într-o copie curată a sursei
  npm ci && npm run build
  # actualizați VERSION și secțiunea din CHANGELOG.md
  php artisan optica:release --source=/cale/copie --out=build \
      --sign-key=/cale/sigura/optica-update.key --min-from=<versiunea minimă> --changelog-hu=changelog-hu.txt
  ```
  Comanda refuză sursele cu pachete de dezvoltare (PHPUnit, Pint) și cele fără `public/build`.

## Import de date (MIG-01…05)
- **Sistem → Import date**, cu permisiunea `system.import`. Formate: CSV (`;` sau `,`, UTF-8 sau Windows-1250) și XLSX.
- Ordinea: pacienții, apoi programările (legate prin ID-ul extern al pacientului). Locațiile, specialiștii și serviciile trebuie să existe deja în platformă; cele lipsă sunt raportate, nu create.
- Pași: încărcare → asocierea coloanelor (recunoscute automat după denumirile uzuale RO/HU) → previzualizare → **simulare obligatorie** (nu scrie nimic) → import în loturi → reconciliere și raport CSV.
- Repetabil: identificatorii externi se păstrează cu prefixul sursei (`evolvo:1234`). Un pacient deja importat se completează doar în câmpurile goale; modificările personalului nu se suprascriu. Același CNP leagă înregistrarea de pacientul existent; numele + telefonul sau data nașterii marchează un posibil duplicat pentru decizie manuală.
- Reluabil: fiecare lot se salvează împreună cu poziția din fișier; un import întrerupt continuă de la primul rând neprocesat, fără duplicate.
- Programările importate nu trimit mesaje (trecute: niciodată; viitoare: doar dacă se bifează opțiunea), ca să nu existe remindere retroactive (MIG-05).
- Fișierul original se păstrează în `storage/app/private/imports/` pe perioada convenită cu beneficiarul. Datele clinice nu se importă prin acest ecran.

## Mediu de dezvoltare
```bash
composer install
cp .env.example .env && php artisan key:generate
# configurați DB_* în .env (MariaDB/MySQL)
php artisan migrate --seed
php artisan test
```
Testele folosesc baza de date `optica_test` (vezi `phpunit.xml`) pe MySQL/MariaDB. Testele de backup, restaurare și actualizare au nevoie și de o bază separată, `optica_scratch` (sau `DB_SCRATCH_DATABASE`), pe care o șterg și o recreează; fără ea, aceste teste sunt sărite. SQLite nu este suportat pentru teste, deoarece testele de concurență au nevoie de blocări reale pe rând.

## Comunicare (email, SMS, WhatsApp)
- Conturile se configurează în **Comunicare → Canale**; datele de acces se păstrează criptat cu `APP_KEY`.
- Pentru Twilio și WhatsApp, adresa de notificare afișată în ecranul contului (`/webhooks/twilio/{id}`, `/webhooks/whatsapp/{id}`) se introduce în contul furnizorului. Site-ul trebuie să fie accesibil prin HTTPS, iar `APP_URL` trebuie să fie exact adresa publică (semnătura Twilio include URL-ul).
- Regulile de trimitere pornesc dezactivate. Nicio programare nu generează mesaje până când un administrator configurează un canal și activează regula.
- Cronul unic rulează și `messages:send` (remindere, campanii, coadă).

## Fișiere încărcate
Fotografiile și celelalte fișiere publice se salvează în `public/uploads/` (fără `storage:link`, care cere symlink). Directorul trebuie să poată fi scris de PHP și **nu se suprascrie niciodată** la actualizare. Documentele private ale pacienților (investigații, documente externe) se salvează în `storage/app/private/patients/`, în afara zonei publice, și se descarcă numai prin aplicație (D-018). Directorul trebuie inclus în backup și nu se suprascrie la actualizare.

## Cheia de criptare (`APP_KEY`)
Fișele medicale, prescripțiile și CNP-urile sunt criptate cu `APP_KEY` din `.env` (D-015). **Fără această cheie, datele clinice dintr-un backup nu pot fi citite.** Cheia:
- se generează o singură dată, la instalare, și nu se schimbă niciodată pe un sistem cu date;
- se păstrează separat de backupul bazei de date, într-un loc sigur (de exemplu, managerul de parole al administratorului);
- nu se trimite prin email și nu se pune în tichete de suport.

## Primul administrator
Instalatorul creează primul superadministrator. În dezvoltare (sau prin *Terminal* din cPanel) există și comanda:
```
php artisan optica:create-admin admin@exemplu.ro "Nume Prenume" --locale=ro
```
La prima autentificare, superadministratorul trebuie să activeze verificarea în doi pași.

## Resurse frontend
CSS-ul și JS-ul se compilează **înainte** de împachetare (`npm ci && npm run build`) și se livrează în `public/build/`. Pe server nu se rulează Node.

## Fusul orar
Baza de date stochează UTC (`APP_TIMEZONE` rămâne `UTC`). Ora clinicilor este `CLINIC_TIMEZONE=Europe/Bucharest`. Nu modificați `APP_TIMEZONE` după instalare.

## Variabile de configurare relevante
| Variabilă | Implicit | Rol |
|---|---|---|
| `CLINIC_TIMEZONE` | `Europe/Bucharest` | fusul orar al programelor |
| `BOOKING_HOLD_MINUTES` | 10 | rezervarea temporară din formularul public |
| `BOOKING_PENDING_EXPIRY_HOURS` | 24 | cât blochează o cerere neconfirmată |
| `BOOKING_DEFAULT_TRAVEL_MINUTES` | 30 | timpul de deplasare implicit între locații |
| `CALENDAR_POLL_SECONDS` | 15 | reîmprospătarea calendarului CRM |
| `TWO_FACTOR_ENFORCED` | true | 2FA obligatoriu pentru rolurile privilegiate (nu se dezactivează în producție) |
| `BACKUP_KEEP` | 7 | câte backupuri regulate se păstrează |
| `UPDATE_PUBLIC_KEYS` | — | chei publice suplimentare pentru pachetele de actualizare |
