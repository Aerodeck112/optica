# Instalare pe cPanel — anotherworldcluj.ro

Ghid pas cu pas pentru instalarea în producție pe un cont de găzduire cPanel. Durata estimată: 60–90 de minute,
fără configurarea integrărilor externe (acelea sunt descrise în `docs/CONTURI-EXTERNE.md`).

Procedura a fost verificată pe o bază de date nouă în modul `production`: migrații, date de bază, cont de administrator,
cache-uri și primele pagini (site, panou, `/up`, `sitemap.xml`). Nu a fost verificată pe un server cPanel anume:
pașii specifici panoului (nume de meniuri, căi PHP) pot diferi ușor de la un furnizor de găzduire la altul.

---

## 1. Cerințe

| Cerință | Valoare |
|---|---|
| PHP (web și CLI, aceeași versiune) | **8.3 sau mai nou** |
| Extensii PHP | `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `fileinfo`, `openssl`, `curl`, `exif`, `sodium`, `xml`, `dom`, `ctype`, `tokenizer` |
| Bază de date | MariaDB ≥ 10.6 sau MySQL ≥ 8.0, `utf8mb4` |
| Funcții PHP permise | `proc_open` nu este necesar în producție; `symlink` e util pentru `storage:link` (alternativă mai jos) |
| Cron | o intrare la fiecare minut |
| SSL | certificat valid (AutoSSL / Let's Encrypt din cPanel) — obligatoriu pentru plăți, cookie-uri securizate și HSTS |
| Spațiu | ~400 MB aplicația + media și copiile de siguranță |

Setări PHP recomandate (cPanel → **MultiPHP INI Editor**, pentru domeniu):

```
memory_limit = 512M          ; procesarea imaginilor de până la 40 MP
upload_max_filesize = 48M    ; încărcări media de până la 40 MB
post_max_size = 50M
max_execution_time = 120
max_input_vars = 5000        ; formulare mari în panou (pagini cu multe blocuri)
```

Verificare rapidă după instalare: `php -v` și `php -m` în Terminal (cPanel → **Terminal**). Dacă Terminalul nu este disponibil,
cereți furnizorului acces SSH sau folosiți pașii alternativi marcați „fără SSH”.

> **Calea către PHP CLI.** Pe multe servere cPanel, `php` din Terminal este altă versiune decât cea a site-ului.
> Folosiți calea explicită, de exemplu `/opt/cpanel/ea-php83/root/usr/bin/php` (verificați cu `ls /opt/cpanel/`).
> În acest ghid, `php` înseamnă această cale.

---

## 2. Pregătirea pachetului

Pe server **nu** este nevoie de Node.js. Aveți două variante:

**A. Arhiva din CI (recomandat).** La fiecare actualizare a ramurii `main`, GitHub Actions rulează testele și produce
artefactul `anotherworldcluj-cpanel` (fișier `.zip` cu `vendor/` fără pachete de dezvoltare și resursele compilate).
Îl descărcați din fila **Actions** a depozitului → rularea respectivă → **Artifacts**.

**B. Construire locală** (pe un calculator cu PHP 8.3, Composer și Node 22):

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
rm -rf node_modules
zip -r anotherworldcluj.zip . -x '.git/*' 'tests/*' 'storage/logs/*' '.env'
```

---

## 3. Încărcarea fișierelor

Aplicația se instalează **în afara** `public_html`; doar folderul `public/` trebuie să fie accesibil din web.

1. cPanel → **File Manager** → în directorul principal (`/home/UTILIZATOR`) creați folderul `anotherworldcluj`.
2. Încărcați arhiva în acest folder și folosiți **Extract**.
3. Verificați că există `/home/UTILIZATOR/anotherworldcluj/artisan` și `/home/UTILIZATOR/anotherworldcluj/public/index.php`.

### Documentul rădăcină al domeniului

**Varianta 1 (preferată):** cPanel → **Domains** → `anotherworldcluj.ro` → **Manage** → *Document Root* =
`anotherworldcluj/public`. Salvați.

**Varianta 2 (dacă documentul rădăcină al domeniului principal nu poate fi schimbat):** din Terminal,
după ce ați salvat conținutul existent din `public_html`:

```bash
cd ~
mv public_html public_html.vechi
ln -s ~/anotherworldcluj/public ~/public_html
```

Nu copiați conținutul lui `public/` în `public_html` separat de aplicație: actualizările ulterioare s-ar desincroniza.

---

## 4. Baza de date

cPanel → **MySQL® Databases**:

1. Creați baza (ex. `utilizator_aw`).
2. Creați un utilizator cu parolă generată (ex. `utilizator_aw`).
3. Adăugați utilizatorul la bază cu **ALL PRIVILEGES**.

Colaționarea implicită `utf8mb4_unicode_ci` este cea corectă (diacritice ș, ț, ă și caractere maghiare).

---

## 5. Fișierul `.env`

```bash
cd ~/anotherworldcluj
cp .env.example .env
php artisan key:generate
```

Editați `.env` (File Manager → Edit) și completați:

| Cheie | Ce trebuie pus |
|---|---|
| `APP_URL` | `https://anotherworldcluj.ro` (fără `/` la final) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | datele de la pasul 4 |
| `MAIL_*` | o căsuță de e-mail creată în cPanel → **Email Accounts** (ex. `no-reply@anotherworldcluj.ro`), server `mail.anotherworldcluj.ro`, port 465, `MAIL_SCHEME=smtps`. Folosită **doar** pentru e-mailurile către personal (resetare parolă, cod 2FA). |
| `AW_BACKUP_PASSWORD` | o parolă lungă, generată. **Păstrați-o și în afara serverului** (manager de parole al firmei): fără ea, copiile de siguranță nu pot fi restaurate. |
| `AW_MESSAGING_SANDBOX` | `false` în producție, **doar după** ce integrarea de e-mail/SMS este configurată și testată. Cât timp este `true`, niciun mesaj nu pleacă spre clienți. |

Nu puneți în `.env` cheile Stripe, SMS, Claude sau ANAF: acestea se introduc în panou (Sistem → Integrări) și se păstrează criptat
în baza de date.

Protejați fișierul: `chmod 600 .env`.

---

## 6. Instalarea aplicației

```bash
cd ~/anotherworldcluj
php artisan migrate --force
php artisan db:seed --force                 # roluri, cote TVA, unități, conținut inițial, documente juridice în ciornă
php artisan storage:link                    # imaginile încărcate în panou (public/storage)
php artisan optimize
php artisan filament:optimize
chmod -R ug+rwX storage bootstrap/cache
```

`db:seed` instalează **numai** date sigure pentru producție: nu creează conturi, nu completează prețuri, capacități sau date
comerciale și nu încarcă datele demonstrative (acestea există doar în mediul local, cu `AW_SEED_DEMO=true`).

**Fără `symlink`** (dacă `storage:link` dă eroare): folosiți varianta fără Terminal de mai jos (creează legătura cu `ln -s`).

### Fără Terminal — instalare din browser (recomandat)

1. Completați `.env` (secțiunea 5), fără `APP_KEY` — pagina o generează.
2. Deschideți `https://anotherworldcluj.ro/instalare.php`.
3. Pagina verifică serverul (versiune PHP, extensii, drepturi, conexiunea la baza de date). Rezolvați punctele marcate cu ✗.
4. Introduceți parola bazei de date (valoarea `DB_PASSWORD` din `.env` — confirmă că aveți acces la server) și datele
   administratorului (nume, e-mail, parolă de minim 12 caractere cu litere mari, mici și cifre) → **Instalează**.
5. La final, pagina afișează comanda pentru cron-ul permanent (secțiunea 7), se dezactivează și se șterge singură.
   Dacă nu a reușit să se șteargă, ștergeți manual `public/instalare.php`.

Dacă instalarea se oprește la un pas, corectați cauza și apăsați din nou **Instalează**: pașii deja făcuți nu se repetă.
După 10 parole greșite pagina se blochează (se deblochează ștergând `storage/framework/instalare-incercari`).

### Fără Terminal — alternativă din Cron Jobs

Dacă cPanel nu are Terminal și nici acces SSH, pașii de mai sus (inclusiv cheia aplicației și contul de administrator)
se rulează o singură dată prin scriptul `tools/cpanel/instalare.sh`:

1. Completați `.env` (secțiunea 5), fără `APP_KEY` — scriptul o generează.
2. cPanel → **Cron Jobs** → *Once Per Minute* → comanda:
   ```
   bash $HOME/anotherworldcluj/tools/cpanel/instalare.sh adresa@anotherworldcluj.ro "Nume Prenume"
   ```
3. După 2–3 minute, în File Manager, în folderul principal, deschideți `aw-instalare.log`. Trebuie să se termine cu
   `=== GATA` și să conțină linkul de setare a parolei pentru administrator.
4. **Ștergeți Cron Job-ul de instalare** și apoi fișierul `aw-instalare.log` (conține linkul de setare a parolei).

Scriptul găsește singur PHP 8.3+, verifică extensiile, rulează o singură dată (fișierul `aw-instalare.gata`) și scrie
calea PHP găsită în `aw-php-cale.txt` — folosită apoi la cron-ul permanent. Dacă jurnalul arată o eroare, corectați
cauza (de regulă datele bazei de date din `.env`), ștergeți `aw-instalare.gata` și scriptul rulează din nou la minutul următor.

### Primul cont de administrator

```bash
php artisan aw:create-admin adresa@anotherworldcluj.ro "Nume Prenume" --role=super_admin
```

Se trimite pe e-mail un link de setare a parolei (valabil 60 de minute). Dacă e-mailul nu este încă configurat, adăugați
`--show-link` și linkul se afișează în terminal. La prima autentificare, panoul cere configurarea autentificării în doi
pași (aplicație de tip Google Authenticator / Microsoft Authenticator). Codurile de recuperare afișate atunci se păstrează offline.

Restul conturilor (manager, recepție, contabil etc.) se creează din panou: **Sistem → Utilizatori**.

---

## 7. Cron (obligatoriu)

cPanel → **Cron Jobs** → *Common settings: Once per minute* → comanda:

```
cd /home/UTILIZATOR/anotherworldcluj && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Acest singur cron rulează tot ce este programat:

| Ce | Când |
|---|---|
| coada de mesaje (worker scurt, se oprește singur) | în fiecare minut |
| expirarea rezervărilor neconfirmate/neplătite | în fiecare minut |
| remindere și notificări programate, campanii | în fiecare minut |
| publicarea programată a paginilor | în fiecare minut |
| bonuri fiscale fără răspuns → „incerte” | în fiecare minut |
| stare transmiteri e-Factura | la 10 minute |
| facturi primite prin SPV | la oră |
| backup bază de date (verificat automat) | zilnic, 02:15 |
| backup complet (bază + fișiere) | luni, 02:45 |
| expirare puncte și vouchere | zilnic, 03:10 |
| retenția datelor (ștergeri programate) | zilnic, 03:30 |

Verificare: `php artisan schedule:list` arată următoarele rulări; după 2–3 minute, tabelul `jobs` din baza de date
nu ar trebui să aibă înregistrări vechi (cPanel → phpMyAdmin). Dacă furnizorul limitează cron-ul la 5 sau 15 minute,
remindere și expirări vor întârzia corespunzător — cereți intervalul de 1 minut.

---

## 8. Verificări după instalare

1. `https://anotherworldcluj.ro/up` răspunde `200`.
2. Site-ul se deschide pe HTTPS; `http://` redirecționează (cPanel → **Domains** → *Force HTTPS Redirect* activat).
3. `https://anotherworldcluj.ro/admin` → autentificare → configurare 2FA.
4. Panou → **Configurare → Setări**: completați datele firmei și ale locației (lista „De completat” din tabloul de bord).
5. **Configurare → Program**: programul săptămânal. Fără program, rezervarea online nu oferă ore.
6. **Catalog**: confirmați jocurile disponibile la Cluj și pachetele (preț, durată, capacitate). Fără aceste date,
   site-ul acceptă doar cereri, nu rezervări instant — comportament intenționat, pentru a nu publica date neconfirmate.
7. **Conținut site → Documente juridice**: documentele sunt instalate **în ciornă**; se publică doar după validare juridică.
8. **Financiar → Serii de documente**: seria de facturare stabilită cu contabilul.
9. **Sistem → Integrări**: configurați și testați fiecare integrare (vezi `docs/CONTURI-EXTERNE.md`).
10. Rulați manual un backup și verificați-l: `php artisan aw:backup` (afișează „Verificare OK”).

---

## 9. Actualizări

```bash
cd ~/anotherworldcluj
php artisan aw:backup                       # copie verificată înainte de actualizare
php artisan down --retry=60
# încărcați și dezarhivați noua versiune peste cea existentă (fără a șterge .env și storage/)
php artisan migrate --force
php artisan optimize:clear && php artisan optimize && php artisan filament:optimize
php artisan up
```

---

## 10. Copii de siguranță și restaurare

* Copiile se salvează în `storage/app/private/backups` (în afara zonei publice), criptate AES-256 dacă
  `AW_BACKUP_PASSWORD` este setat. Se păstrează 14 copii zilnice și 4 complete.
* **Păstrați copii și în afara serverului.** Fie prin backup-ul de cont al furnizorului (cPanel → **Backup**), fie configurând
  `AW_BACKUP_REMOTE_DISK` către un spațiu extern (S3/SFTP), fie descărcând periodic arhivele.
* Verificare arhivă: `php artisan aw:backup-restore aw-database-AAAALLZZ-HHMMSS.zip --verify-only`
* Restaurare (înlocuiește complet baza curentă; site-ul intră în mentenanță pe durata restaurării):
  `php artisan aw:backup-restore aw-database-AAAALLZZ-HHMMSS.zip`
* Fișierele (imagini, PDF facturi, XML e-Factura) sunt în arhivele `aw-full-*`, în folderul `files/`; se copiază înapoi în
  `storage/app/private` și `storage/app/public`.

Recomandare: o dată pe trimestru, restaurați ultima copie pe un subdomeniu de test (`AW_STAGING=true`), ca să știți că
procedura funcționează și cât durează.

---

## 11. Mediu de test (staging)

Pe un subdomeniu (ex. `test.anotherworldcluj.ro`) cu bază de date separată:

```
APP_ENV=staging
AW_STAGING=true            # noindex + banner „Mediu de test”
AW_MESSAGING_SANDBOX=true  # niciun mesaj către clienți reali
AW_TEST_EMAILS=adresa-voastra@...
```

Integrările de plăți în mod *sandbox/test*, e-Factura pe mediul ANAF **test**.

---

## 12. Probleme frecvente

| Simptom | Cauză probabilă |
|---|---|
| Eroare 500 imediat după instalare | `storage/` sau `bootstrap/cache` fără drept de scriere; `APP_KEY` lipsă; extensie PHP lipsă (`storage/logs/laravel-*.log`) |
| Imaginile încărcate nu apar | lipsește `public/storage` (`php artisan storage:link`) |
| Remindere / e-mailuri nu pleacă | cron neconfigurat sau `AW_MESSAGING_SANDBOX=true`; integrarea E-mail neconfigurată |
| Încărcarea unei imagini mari eșuează | `upload_max_filesize`, `post_max_size` sau `memory_limit` prea mici |
| Panoul nu acceptă codul 2FA | ora serverului greșită — cereți sincronizarea NTP; codurile de recuperare permit accesul între timp |
| După actualizare se văd setări vechi | `php artisan optimize:clear && php artisan optimize` |
| „Too many requests” la testare | limitele anti-abuz pe formulare (6/minut/IP) — normal; așteptați un minut |

## 13. Când este nevoie de VPS

Vezi `docs/ARHITECTURA.md`, secțiunea „Când este nevoie de VPS”. Pentru o singură locație, cPanel este suficient.
