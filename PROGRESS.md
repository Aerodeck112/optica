# Progres

Ultima actualizare: 17 octombrie 2026. Versiune: `0.3.0-dev`.

Statusurile urmează LIV-01: **implementat și testat**, **neconfigurat** (necesită credențiale sau furnizor), **în așteptarea datelor**, **neimplementat**. O interfață fără backend nu este considerată funcție finalizată.

## Implementat și testat

| Componentă | Ce face | Teste |
|---|---|---|
| Fundație Laravel 13 + MariaDB | proiect, configurare RO, UTC, fus orar al clinicii | — |
| Model de date de bază | locații, resurse, specialiști, servicii, oferte, orare, segmente, excepții, pacienți, relații, programări, evenimente, vizite, setări, audit, traduceri | migrările rulează pe MariaDB 10.11 |
| Motor de disponibilitate | orare cu perioade, săptămâni alternante, segmente multiple, concedii, sărbători, excepții parțiale, disponibilitate suplimentară, DST, reguli online (comutatoare, termen minim/maxim) | `AvailabilityTest` (7) |
| Rezervare tranzacțională | creare, idempotență, reprogramare atomică, anulare cu motiv, tranziții controlate, versiuni (conflict de editare), rezervări temporare și expirarea lor, tampoane, resurse, timp de deplasare, capacitate cu durate diferite | `BookingTest` (15) |
| Concurență reală | procese paralele pe MariaDB: un singur câștigător pe slot, capacitate respectată, fără suprapuneri între locații | `ConcurrentBookingTest` (3). Verificat prin mutație: fără blocare, testul pică (8 din 8 rezervări reușite). |
| Permisiuni | catalog stabil, refuz implicit, domeniu pe locații, roluri implicite, superadmin fără acces clinic implicit, seeder idempotent | `AccessControlTest` (6) |
| Traduceri personalizate | draft → publicare, istoric, reset, protecția variabilelor, persistență peste fișierele standard | `TranslationOverridesTest` (5) |
| Cron | heartbeat, expirarea rezervărilor, procesarea cozii în loturi | `php artisan schedule:list` |
| Date reale de pornire | 8 locații (adrese, telefoane, programul magazinelor RO/HU), 12 servicii RO/HU, telefoanele de programare, cifrele confirmate; idempotent, nu suprascrie editările | `CatalogAdminTest::test_site_content_seeder_is_idempotent_and_keeps_edits` |
| Autentificare | login cu limitare și mesaj generic, 2FA TOTP (obligatoriu pentru rolurile privilegiate), resetare parolă, dezactivare cu efect imediat, jurnal de securitate | `LoginTest` (8) |
| Limba angajatului | RO/HU per cont, comutator care păstrează pagina, filtrele și datele nesalvate din formular | `StaffLocaleTest` (3) + verificare în browser |
| Layout CRM | navigare după permisiuni, responsive (390/768/1440 px fără derulare orizontală), focus vizibil, legătură „sari la conținut”, mișcare redusă respectată | capturi la 3 lățimi |
| Admin → Locații | listă cu căutare, editare RO/HU alăturate, contact, coordonate, programul magazinului, vizibilitate, programare online, mod de confirmare, ciornă/publicare, ștergere protejată, restaurare | `CatalogAdminTest` |
| Admin → Medici și optometriști | adăugare, editare, ștergere, restaurare; fotografie re-encodată (fără metadate); profil public RO/HU; limbi vorbite; locațiile în care lucrează și programarea online per locație; ciornă/publicare | `CatalogAdminTest` |
| Admin → Servicii | adăugare, editare RO/HU, durată, tampoane, preț orientativ, culoare în calendar, categorie, ciornă/publicare, ștergere protejată | `CatalogAdminTest` |
| Comandă `optica:create-admin` | creează primul superadministrator până la instalatorul web | verificat manual |
| Admin → Utilizatori | conturi cu roluri, locații, legătura cu profilul de specialist; invitație prin email în limba angajatului (link 72 h); resetare 2FA; închiderea sesiunilor; protecția ultimului superadministrator; jurnal al modificărilor de drepturi | `UserAdminTest` (7) |
| Admin → Roluri | matrice de permisiuni RO/HU, permisiuni clinice marcate, roluri personalizate, diferența de permisiuni jurnalizată | `UserAdminTest` |
| Servicii oferite | per specialist și locație: durată, capacitate, tampoane, preț, aparatură necesară, online; setări online per locație (termen minim, orizont, pauză cu mesaj RO/HU, notificare) | `PlanningAdminTest` |
| Cabinete și aparatură | per locație, folosite de motorul de programări | `PlanningAdminTest` |
| Orare de lucru | perioadă, săptămâni alternante, intervale pe zile cu grilă, online, servicii permise, cabinet; copiere luni → marți–vineri; avertizare de suprapunere între locații; acces limitat la locațiile proprii | `PlanningAdminTest` |
| Concedii și excepții | concediu, sărbătoare, curs, închidere, program suplimentar; pe specialist sau toți, pe locație sau toate | `PlanningAdminTest` |
| Programări afectate | la modificarea orarului sau a excepțiilor, programările care nu mai încap sunt listate, nu șterse | `PlanningAdminTest` |
| Calendar CRM | zi (coloane pe specialist), săptămână, lună, agendă (implicită pe telefon); timp de lucru vizibil; timpul petrecut în altă locație afișat fără date de pacient; linie „acum”; actualizare automată la 15 s cu starea conexiunii; creare din interval cu căutare/adăugare pacient și avertizare de duplicat; detalii, schimbare status, mutare (drag-and-drop și formular), anulare cu motiv, istoric; listă de așteptare cu propuneri la anulare; filtre pe locație, specialist, status | `CalendarTest` (10) + capturi 1440/390 px |
| Registrul de pacienți (bază) | cod intern `P000123`, căutare după nume, telefon (orice format) sau cod, adăugare rapidă, sugestie de duplicate fără unire automată | `CalendarTest` |
| Date demonstrative | `DemoDataSeeder`: specialiști, pacienți și programări fictive marcate DEMO; refuză să ruleze în producție | rulat manual |

Total: **79 de teste, toate trec** (`php artisan test`).

## Neconfigurat (necesită date externe)
- Furnizorii de email, WhatsApp și SMS: se aleg la audit (COM-01).
- Hostingul cPanel: versiunea PHP, extensiile, cronul și document root trebuie verificate (INS-02, D-001).

## În așteptarea datelor de la beneficiar
- Medicii și optometriștii: se introduc din Admin → Medici și optometriști (decizia N-06).
- Orarele de consultații, duratele, resursele, capacitățile și modul de confirmare per locație.
- Tarifele (dacă se publică).
- Exporturile autorizate din Evolvo (MIG-01) – la finalul proiectului, conform deciziei beneficiarului.
- Textele legale și de consimțământ validate (SEC-05).
- Șabloanele clinice validate de personal medical (MED-01).

## Neimplementat încă (ordinea planificată)
1. Fluxul public de programare (`/programare`, RO/HU), rezervare temporară, linkuri semnate de anulare și reprogramare, rate limiting.
2. Site public + CMS (pagini, secțiuni, meniuri, media, versiuni, SEO, redirecturi, import din site-ul actual).
3. Pacienți (fișă completă, duplicate, unire), fișe medicale, prescripții, documente private, consimțăminte.
4. Leaduri, sarcini, comenzi de ochelari, financiar operațional.
5. Comunicare (adaptoare email / WhatsApp / SMS, cozi, webhookuri, remindere, campanii).
6. Rapoarte avansate și exporturi (Excel, CSV, PDF).
7. Editorul vizual de traduceri și centrul de traduceri.
8. Instalatorul web, actualizatorul ZIP semnat, backup/restaurare, panoul de diagnostic.
9. Import din Evolvo (la final).
10. Testele de acceptanță T01–T12 la nivel HTTP/UI, inclusiv lățimile 390 / 768 / 1440 px.
