# Progres

Ultima actualizare: 17 octombrie 2026. Versiune: `0.1.0-dev`.

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

Total: **36 de teste, toate trec** (`php artisan test`).

## Neconfigurat (necesită date externe)
- Furnizorii de email, WhatsApp și SMS: se aleg la audit (COM-01).
- Hostingul cPanel: versiunea PHP, extensiile, cronul și document root trebuie verificate (INS-02, D-001).

## În așteptarea datelor de la beneficiar
- Confirmarea neconcordanțelor N-01…N-07 (DECISIONS.md).
- Lista medicilor și optometriștilor cu date publicabile (nume, fotografie, descriere, servicii, limbi vorbite).
- Orarele de consultații, duratele, resursele, capacitățile și modul de confirmare per locație.
- Tarifele (dacă se publică).
- Exporturile autorizate din Evolvo (MIG-01).
- Textele legale și de consimțământ validate (SEC-05).
- Șabloanele clinice validate de personal medical (MED-01).

## Neimplementat încă (ordinea planificată)
1. Autentificare (login, 2FA, resetare parolă, blocare după încercări), layout CRM/admin RO/HU, comutator de limbă per angajat.
2. Calendarul CRM (zi, săptămână, lună, agendă, polling, panou lateral, drag-and-drop, listă de așteptare) și administrarea orarelor și excepțiilor, inclusiv raportul programărilor afectate de modificarea orarului.
3. Fluxul public de programare (`/programare`, RO/HU), rezervare temporară, linkuri semnate de anulare și reprogramare, rate limiting.
4. Site public + CMS (pagini, secțiuni, meniuri, media, versiuni, SEO, redirecturi, import din site-ul actual).
5. Pacienți (fișă, duplicate, unire), fișe medicale, prescripții, documente private, consimțăminte.
6. Leaduri, sarcini, comenzi de ochelari, financiar operațional.
7. Comunicare (adaptoare email / WhatsApp / SMS, cozi, webhookuri, remindere, campanii).
8. Rapoarte avansate și exporturi (Excel, CSV, PDF).
9. Editorul vizual de traduceri și centrul de traduceri.
10. Instalatorul web, actualizatorul ZIP semnat, backup/restaurare, panoul de diagnostic.
11. Import din Evolvo.
12. Testele de acceptanță T01–T12 la nivel HTTP/UI, inclusiv lățimile 390 / 768 / 1440 px.
