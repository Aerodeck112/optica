# Arhitectura platformei Optica Optofarm

## 1. Stack
| Strat | Tehnologie | Motiv |
|---|---|---|
| Limbaj / framework | PHP ≥ 8.3, Laravel 13 | ARC-01, D-001 |
| Bază de date | MySQL ≥ 8.0 / MariaDB ≥ 10.6, InnoDB, `utf8mb4` | blocări pe rând, tranzacții |
| UI | Blade + Livewire 4 + Alpine.js; CSS Tailwind compilat la build | D-002 |
| Cozi | driver `database`, procesare prin cron | ARC-02, D-006 |
| PDF | `barryvdh/laravel-dompdf` (PHP pur) | MED-04, RAP-05 |
| Excel / CSV | `openspout/openspout` (PHP pur, streaming) | RAP-05 |
| 2FA | `pragmarx/google2fa` + `bacon/bacon-qr-code` | SEC-01 |
| Semnătură pachete | `ext-sodium` (Ed25519) | UPD-01, D-009 |

Toate dependențele au licență MIT sau LGPL compatibilă (dompdf). Lista completă este în `composer.lock`.

## 2. Organizarea codului
```
app/
  Domain/Scheduling/       motorul de programări (comun site + CRM)
    Interval.php           interval UTC [start, end)
    WorkingWindows.php     orare + excepții → ferestre UTC per dată
    ConflictChecker.php    suprapuneri, capacitate, resurse, deplasare
    AvailabilityService.php sloturi libere, „primul disponibil”, eligibilitate online
    BookingService.php     singura cale de scriere a programărilor (tranzacții + blocări)
    BookingException.php   erori cu cod stabil și mesaj tradus
  Enums/                   statusuri și surse cu identificatori stabili
  Events/                  evenimente emise după commit
  Http/Middleware/         limba angajatului / limba site-ului
  Models/                  Eloquent
  Support/Access/          catalogul de permisiuni și AccessControl
  Support/Translation/     loader cu suprascrieri din DB, flux draft → publicare
  Domain/Patients/          registrul de pacienți (căutare, adăugare, duplicate)
  Domain/Scheduling/AffectedAppointments.php  programările care nu mai încap în orar
  Livewire/Admin/           catalog, utilizatori, roluri, oferte, aparatură, orare, excepții
  Livewire/Crm/Calendar.php calendarul (toate scrierile prin BookingService)
  Support/Audit/            jurnalul de securitate
  Support/Media/            re-encodarea imaginilor încărcate
  Support/Ui/Navigation.php meniul filtrat după permisiuni
config/optica.php          limbi, fus orar, reguli de rezervare, securitate
lang/{ro,hu}/              textele standard (înlocuite la actualizare)
routes/console.php         sarcinile cronului
```
Modulele următoare urmează aceeași structură: `app/Domain/{Patients,Medical,Leads,Orders,Finance,Messaging,Reports,Cms,System}`.

## 3. Rute (ARC-03)
| Prefix | Public | Limbă | Middleware |
|---|---|---|---|
| `/`, `/hu/…` | da | din URL | `site.locale` |
| `/programare`, `/hu/programare` | da | din URL | `site.locale`, rate limiting |
| `/crm/…` | autentificat | din profilul angajatului | `auth`, `staff.locale`, 2FA pentru roluri privilegiate |
| `/admin/…` | autentificat | din profilul angajatului | `auth`, `staff.locale`, 2FA |
| `/install` | token unic | selectabilă | activ numai înainte de instalare |
| `recovery.php` | token din fișier | selectabilă | independent de Laravel (UPD-06) |

## 4. Modelul de date (dicționar)

### Acces
| Tabel | Rol | Coloane cheie |
|---|---|---|
| `users` | angajați | `locale`, `is_active`, `all_locations`, `two_factor_*`, soft delete |
| `roles` | roluri personalizabile | `key` stabil, `name` {ro, hu}, `is_system` |
| `permissions` | catalog `modul.acțiune` | `key`, `module` |
| `permission_role`, `role_user` | atribuiri | |
| `location_user` | domeniul pe locații | |

### Catalog și programare
| Tabel | Rol | Coloane cheie |
|---|---|---|
| `locations` | puncte de lucru | `content`/`published_content` {ro, hu}, adresă, coordonate, `opening_hours` (magazin), `is_public`, `online_booking_enabled`, `confirmation_mode`, `external_ref` |
| `resources` | cabinete și aparatură per locație | `type`, `name` {ro, hu} |
| `specialists` | medici și optometriști | `type` (ophthalmologist / optometrist / other), `display_name`, `content`/`published_content`, `photo_path`, `languages`, `is_public` |
| `services` | servicii | `content`/`published_content`, `default_duration`, `buffer_before/after`, `default_price`, `color` |
| `location_specialist` | specialist activ în locație + setări online | `online_enabled`, `online_paused`, `content` (alias, info, mesaj de pauză), `min_lead_minutes`, `max_advance_days` |
| `offerings` | combinația rezervabilă locație–specialist–serviciu | `duration_minutes`, tampoane, `capacity` (implicit 1), `resource_type`, `price`, `online_enabled` |
| `location_travel_times` | timp de deplasare între locații | implicit `optica.booking.default_travel_minutes` |
| `schedules` | orar per specialist × locație | `valid_from/to`, `cycle_weeks`, `cycle_anchor_date` |
| `schedule_segments` | intervale zilnice | `cycle_week`, `weekday`, `segment`, ore locale, `slot_minutes`, `online_enabled`, `service_ids`, `resource_id` |
| `schedule_exceptions` | concedii, sărbători, închideri, disponibilitate suplimentară | domeniu `specialist_id`/`location_id` (nul = toate), `kind`, `reason`, interval de date, ore opționale |

### Pacienți și activitate
| Tabel | Rol | Coloane cheie |
|---|---|---|
| `patients` | fișa unică | `code`, `phone_normalized` (neunic), `locale`, `cnp_encrypted` + `cnp_hash`, `legal_guardian_id`, `merged_into_id`, `external_ref` |
| `patient_relations` | relații de familie | `relation` |
| `appointments` | programări | ore UTC `starts_at/ends_at`, `block_*` (cu tampoane), `status`, `source`, `locale`, date de contact, `hold_expires_at`, motiv și autor anulare, `idempotency_key` (unic), `lock_version`, `reschedule_count` |
| `appointment_events` | jurnalul programării | `type`, status vechi/nou, ore vechi/noi, `actor` |
| `visits` | consultația efectiv realizată (≠ programare) | `appointment_id` unic, `started_at/ended_at` |

### Sistem
| Tabel | Rol |
|---|---|
| `settings` | setări cheie–valoare (inclusiv heartbeat-ul cronului) |
| `audit_logs` | jurnal de securitate, fără conținut clinic sau secrete |
| `translation_overrides` | corecturi de traducere (draft / publicat) |
| `translation_revisions` | istoricul traducerilor (ciornă, publicare, import, restaurare, revenire) |
| `translation_usages` | paginile pe care a apărut fiecare cheie în modul de traducere |
| `jobs`, `failed_jobs`, `job_batches`, `cache`, `cache_locks`, `sessions` | infrastructură Laravel pe DB |

| `waitlist_entries` | lista de așteptare per locație |

Tabele planificate: `medical_records`, `medical_record_amendments`, `prescriptions`, `documents`, `consents`, `leads`, `tasks`, `orders`, `order_status_events`, `payments`, `message_templates`, `messages`, `message_attempts`, `campaigns`, `pages`, `page_versions`, `media`, `menus`, `redirects`, `import_batches`, `import_rows`, `saved_report_filters`, `update_runs`.

## 5. Motorul de disponibilitate
1. **Ferestre de lucru** (`WorkingWindows`): pentru fiecare dată locală se aleg orarele active ale combinației specialist × locație. Se calculează săptămâna din ciclu, se iau segmentele zilei (filtrate pe serviciu și, pentru online, pe flagul `online_enabled`), se scad excepțiile „indisponibil” (zi întreagă sau interval) și se adaugă excepțiile „disponibil”. Orele locale se convertesc în UTC **pe fiecare dată în parte**, deci trecerea la ora de vară sau de iarnă nu deplasează programul.
2. **Sloturi** (`AvailabilityService::slots`): pe grila segmentului (implicit 15 min), cu durata ofertei; se aplică termenele minim și maxim pentru online.
3. **Conflicte** (`ConflictChecker::check`), aceleași reguli pentru listare și pentru scriere:
   - același specialist în **altă locație**: suprapunerea este interzisă, cu timpul de deplasare adăugat în ambele sensuri;
   - aceeași locație: se suprapun doar programări ale aceluiași serviciu, în limita capacității (vârf calculat prin baleiere, deci corect și când duratele diferă);
   - resurse: se caută o resursă liberă de tipul cerut (sau resursa segmentului).
4. **Scrierea** (`BookingService`): tranzacție → `SELECT … FOR UPDATE` pe specialist (și pe specialistul vechi la reprogramare, în ordine crescătoare) și pe resurse → reverificare completă → scriere + eveniment → notificări numai după commit.

Rezervările „ținute” (`held`) și cererile „în așteptare” (`pending`) blochează timpul până la `hold_expires_at`. Cronul le marchează `expired`.

## 6. Permisiuni
Vezi PERMISSIONS.md. `AccessControl` rezolvă permisiunile o singură dată per cerere (singleton scoped). `Gate::before` tratează permisiunile din catalog cu argument opțional de locație. Policies per model vor adăuga relația cu pacientul.

### Livewire și securitate
Cererile Livewire (`/livewire/update`) reaplică middleware-ul persistent: autentificare, cont activ, 2FA și limba angajatului (`AppServiceProvider`). Fiecare componentă de admin verifică permisiunea în `boot` (trait `GuardsCatalog`), deci o cerere construită manual este refuzată la fel ca pagina.

## 7. Bilingvism
- Interfața: chei stabile în `lang/{ro,hu}/*.php`. `OverrideFileLoader` aplică peste ele corecturile publicate din `translation_overrides`. Cache-ul este versionat: la publicare crește versiunea, fără golirea cache-ului global și fără pierderea sesiunilor (TRD-04).
- Conținutul: coloane JSON `{ro: {...}, hu: {...}}`, cu `content` (draft) și `published_content`.
- Comunicările: limba pacientului (`patients.locale` / `appointments.locale`), independentă de limba angajatului.
- Textele clinice introduse de personal nu se traduc automat și nu sunt editabile prin modul de traducere (TRD-02).
- Modul de traducere (D-022): `RecordingTranslator` (extinde translatorul Laravel) notează cheile afișate într-un `TranslationRecorder` per cerere; middleware-ul `TranslationMode` adaugă lista la sfârșitul paginii, iar `components/translation-mode` marchează textele și deschide `Translations\InlineEditor`. Centrul de traduceri (`Admin\TranslationCenter`) citește toate cheile prin `TranslationCatalog`.

## 8. Cron și cozi
O singură intrare cron: `* * * * * php /cale/artisan schedule:run`. Sarcini: heartbeat, `bookings:expire`, `queue:work --stop-when-empty --max-time=50` (cu blocare împotriva rulărilor simultane), `messages:send`, rapoarte periodice, ștergerea exporturilor, `optica:backup --scheduled`. În mod mentenanță, planificatorul nu pornește sarcini.

## 9. Instalator (D-023)
- Pachetul ZIP (`optica:release`, `App\Domain\System\ReleaseBuilder`) conține `vendor/` fără pachete de dezvoltare, `public/build/`, scheletul `storage/` și `release.json`. Pe server nu se rulează Composer sau npm.
- Prima cerere fără `.env`: `App\Support\Install\EnvBootstrap` (apelat din `bootstrap/app.php`) scrie un `.env` de producție cu `APP_KEY` nou, sesiuni și cache pe fișiere, și recreează directoarele lipsă.
- Middleware-ul global `EnsureInstalled` trimite orice cerere la `/install` cât timp lipsește `storage/app/installed.lock`; după instalare, `/install` răspunde cu 404.
- `Install\InstallController` (formulare simple, fără Livewire, ca să funcționeze înainte de baza de date): token unic din `storage/app/install-token.txt` → verificarea serverului → baza de date (versiune, tabele existente, drept de creare) → migrări + `DatabaseSeeder` (fără date demonstrative) → superadministrator → adresă, fus orar, SMTP → finalizare (lock, token de recuperare, linia de cron). Fiecare pas verifică pe server că pașii anteriori sunt făcuți.
- Document root = `public/`; alternativa este `.htaccess` din rădăcină, care trimite totul în `public/`.

## 10. Backup, actualizări și recuperare (D-024, D-025)
- `App\Support\Recovery\RecoveryKit` nu depinde de Laravel: export și restaurare a bazei de date în PHP (o instrucțiune pe linie, marcaj de completitudine), arhive de fișiere, scriere atomică, criptare XChaCha20-Poly1305 a pieselor de backup (cheie derivată din `APP_KEY`), mod mentenanță, jurnalul actualizării și rollback. Același cod este folosit de aplicație și de `public/recovery.php`.
- `BackupManager`: backup verificat (SHA-256 per parte, decriptare de probă, marcaj, `CHECKCONS`), listare fără bază de date (manifestele de pe disc), restaurare cu backup de siguranță, retenție.
- `UpdatePackage`: semnătura Ed25519 a `manifest.json` cu cheile din `resources/update-keys.txt` / `UPDATE_PUBLIC_KEYS`, versiuni, PHP, spațiu, limite, căi sigure, fără symlinkuri, fără fișiere protejate, corespondență 1:1 cu manifestul, sume la extragere.
- `UpdateRunner`: pași scurți și repetabili (`backup → maintenance → apply (loturi) → migrate → finalize → check → done`), scriși în `storage/app/private/updates/current.json` înainte și după fiecare pas; o eroare după backup declanșează rollback automat; istoricul se oglindește în `update_runs` (rescris după o restaurare a bazei de date). Interfața (Sistem → Actualizări) avansează câte un pas pe cerere; cookie-ul de ocolire a mentenanței rămâne necriptat (îl semnează Laravel).
- Fișierele șterse de o versiune se deduc din `release.json` (versiunea instalată) minus manifestul noii versiuni.

## 10b. Import (D-026)
- `App\Domain\Import\ImportRunner`: încărcare (fișierul original + rânduri normalizate în JSON Lines), asociere, previzualizare, simulare, loturi în tranzacție împreună cu cursorul (reluare fără duplicate), rândurile repetate în fișier calculate la pornire, raport și reconciliere. Ținte: `PatientImport`, `AppointmentImport`. Tabel `import_runs`.

## 11. Securitate
Parolele sunt hash-uite (bcrypt). CNP-ul este criptat cu cheia aplicației și are un hash HMAC pentru căutare. Documentele vor fi stocate în `storage/app/private` și servite numai prin controller cu autorizare. Jurnalele nu conțin secrete sau date clinice. Analytics-ul nu se încarcă în CRM. Detalii în SEC-01…07.
