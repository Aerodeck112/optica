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

## Neconcordanțe constatate în surse – rezolvate de beneficiar (17.10.2026)

| # | Constatare | Decizia beneficiarului | Aplicare |
|---|---|---|---|
| N-01 | 8 locații (Acasă, footer) față de „10 puncte de lucru” (Fabrica de lentile) | Sunt valabile locațiile din footer (8). | `SiteContentSeeder` importă cele 8 locații. La importul paginii Fabrica, cifra devine 8. |
| N-02 | 40.000 clienți / 50.000 pacienți pe an / ~70 specialiști | Se folosesc **50.000** și **70**. | Setarea `site.stats`; contorul „40000” se înlocuiește cu 50.000. |
| N-03 | „Str. Republicii nr. 5” față de „Piața Republicii 5” | **Piața Republicii nr. 5**. | Adresa RO corectată. Varianta HU („Szentgyörgy tér (Köztársaság tér) 5. szám”) e propusă după textul HU existent și poate fi editată din admin. |
| N-04 | Bd. 1 Decembrie 1918 nr. 49 inactivă online în Evolvo | Locația este **activă**, inclusiv pentru programare online. | `online_booking_enabled = true`. |
| N-05 | Două telefoane de programare | Se afișează **ambele**: 0365 430 939 și +40 265 212 304. | Setarea `site.booking_phones`. |
| N-06 | Pagina Medici nu listează medicii | Se publică **doar ce există pe site** (niciun profil individual). Medicii se adaugă, se editează și se șterg din admin. | Nu se importă specialiști. Modulul Admin → Specialiști are CRUD complet. |
| N-07 | Programul magazinelor față de orarele de consultații | Programul magazinelor se importă de pe site. Orarele de consultații se configurează în CRM. | `locations.opening_hours`. |
| — | Exporturile Evolvo | Se fac **la final**. | Importul (MIG-01…05) rămâne ultimul pas. |

## Presupuneri
- P-01: Limbile platformei sunt RO și HU. Evolvo are și „Informații medic EN”, dar caietul nu cere engleza; EN nu se implementează.
- P-02: Modul de confirmare implicit pentru programările online este „prin recepție” (în așteptare). Se poate trece pe „automat” per locație.
- P-03: O rezervare temporară (în timpul completării formularului public) blochează slotul timp de 10 minute (configurabil). O solicitare în așteptare blochează slotul până la decizia recepției sau până la expirare (implicit 24 h, configurabil).
- P-04: Durata serviciilor nu este publicată. Valoarea inițială este 30 de minute (placeholder) și se ajustează pe fiecare serviciu și pe fiecare combinație locație–specialist din admin.
