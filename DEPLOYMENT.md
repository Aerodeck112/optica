# Instalare și operare

> **Stare:** instalatorul web și pachetul ZIP nu sunt încă implementate (PROGRESS.md). Acest document descrie mediul de dezvoltare și cerințele cPanel stabilite până acum. Secțiunile marcate „planificat” se completează când componentele sunt gata.

## Cerințe server (de verificat la auditul hostingului)
- PHP **8.3 sau mai nou** (în cPanel: *MultiPHP Manager* sau *Select PHP Version*).
- Extensii PHP: `pdo_mysql`, `mbstring`, `intl`, `sodium`, `zip`, `gd`, `fileinfo`, `openssl`, `ctype`, `tokenizer`, `xml`, `curl`, `bcmath` (opțional).
- MySQL 8.0+ sau MariaDB 10.6+, cu InnoDB și `utf8mb4`.
- Cron cPanel cu rulare la fiecare minut.
- Funcții PHP permise: `proc_open` (pentru `schedule:run`), `symlink` (opțional).
- Spațiu: minimum 1 GB plus documentele și backup-urile.
- **Nu sunt necesare** Node.js, Composer, SSH, Docker, Redis sau WebSocket.

## Linia de cron (unică)
```
* * * * * /usr/local/bin/php /home/UTILIZATOR/optica/artisan schedule:run >> /dev/null 2>&1
```
Calea exactă către PHP diferă între servere. Instalatorul o va afișa pe cea corectă (planificat). Cronul rulează:
- heartbeat-ul (panoul de diagnostic avertizează dacă lipsește);
- expirarea rezervărilor temporare și a cererilor neconfirmate;
- procesarea cozii (emailuri, mesaje, exporturi) în loturi de maximum 50 de secunde, fără rulări suprapuse.

## Document root
Varianta recomandată: domeniul indică direct spre directorul `public/` al aplicației (cPanel → *Domains* → *Document Root*: `/home/UTILIZATOR/optica/public`). Codul, `.env`, `storage/` și backup-urile rămân în afara zonei publice (SEC-02).

Varianta pentru hosting cu document root fix (`public_html`), planificată: aplicația se pune în `/home/UTILIZATOR/optica`, iar în `public_html` se copiază numai conținutul lui `public/`, cu un `index.php` care indică spre `../optica`. Instalatorul va genera acest fișier.

## Mediu de dezvoltare
```bash
composer install
cp .env.example .env && php artisan key:generate
# configurați DB_* în .env (MariaDB/MySQL)
php artisan migrate --seed
php artisan test
```
Testele folosesc baza de date `optica_test` (vezi `phpunit.xml`) pe MySQL/MariaDB. SQLite nu este suportat pentru teste, deoarece testele de concurență au nevoie de blocări reale pe rând.

## Fișiere încărcate
Fotografiile și celelalte fișiere publice se salvează în `public/uploads/` (fără `storage:link`, care cere symlink). Directorul trebuie să poată fi scris de PHP și **nu se suprascrie niciodată** la actualizare. Documentele private ale pacienților (investigații, documente externe) se salvează în `storage/app/private/patients/`, în afara zonei publice, și se descarcă numai prin aplicație (D-018). Directorul trebuie inclus în backup și nu se suprascrie la actualizare.

## Cheia de criptare (`APP_KEY`)
Fișele medicale, prescripțiile și CNP-urile sunt criptate cu `APP_KEY` din `.env` (D-015). **Fără această cheie, datele clinice dintr-un backup nu pot fi citite.** Cheia:
- se generează o singură dată, la instalare, și nu se schimbă niciodată pe un sistem cu date;
- se păstrează separat de backupul bazei de date, într-un loc sigur (de exemplu, managerul de parole al administratorului);
- nu se trimite prin email și nu se pune în tichete de suport.

## Primul administrator
Până la instalatorul web, primul cont se creează din linia de comandă (doar în dezvoltare sau prin *Terminal* din cPanel, dacă este disponibil):
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

## Planificat
- Instalarea din ZIP prin `/install` (INS-01, INS-02).
- Actualizarea din „Sistem → Actualizări” (UPD-01…06).
- Backup și restaurare (SEC-06).
- Semnarea pachetelor (cheia privată se păstrează offline, la furnizor).
