# Cerințe și corespondența cu caietul de sarcini

Sursă principală: *Caiet de sarcini Optica Optofarm – Specificație unitară*, versiunea 1.1 din 17 octombrie 2026. Cerințele din promptul beneficiarului (modul de lucru, livrabile) sunt incluse aici.

**Legendă status** (LIV-01)

| Simbol | Înseamnă |
|---|---|
| ✅ | implementat și acoperit de teste automate |
| 🟡 | parțial; detaliile sunt în coloana „Implementare” |
| ⬜ | neimplementat încă |
| ⚙️ | necesită configurare externă (credențiale, furnizor, hosting) |
| 📥 | așteaptă date sau decizii de la beneficiar |

Starea detaliată pe module este în PROGRESS.md.

## 1. Domeniu și surse

| ID | Cerință (rezumat) | Status | Implementare / observații |
|---|---|---|---|
| GEN-01 | Inventar pagini RO/HU, URL-uri, imagini, logo, culori, fonturi, locații, servicii | 🟡 | Inventar în `docs/analiza-site.md`. Importul efectiv al resurselor în CMS este ⬜. |
| GEN-02 | Matrice funcții Evolvo → implementare proprie | 🟡 | `docs/analiza-evolvo.md` + matricea de mai jos. |
| GEN-03 | Fără date personale din capturi; aplicație independentă de Evolvo | ✅ | Fotografiile nu sunt în repository. Testele și datele demonstrative folosesc persoane fictive. |
| GEN-04 | Pagini de referință; 8 locații reverificate | 📥 | Neconcordanțe N-01…N-07 în DECISIONS.md. |

## 2. Arhitectură și date

| ID | Cerință | Status | Implementare |
|---|---|---|---|
| ARC-01 | PHP modular, Laravel, MySQL/MariaDB, server-rendered reactiv | 🟡 | Laravel 13, MariaDB 10.11 în dezvoltare, Livewire 4 instalat. Interfețele nu sunt încă realizate. |
| ARC-02 | Fără Node, Docker, Redis, WebSocket sau SSH în producție; cozi DB prin cron cu blocare | ✅ | `routes/console.php`: un singur cron, coadă `database`, `withoutOverlapping`. |
| ARC-03 | Rute `/`, `/programare`, `/crm`, `/admin` | ⬜ | Definite în arhitectură; rutele nu sunt încă create. |
| ARC-04 | Identificatori stabili, o singură sursă de adevăr, draft–publicare | 🟡 | Tabele unice pentru locații, specialiști și servicii, cu `content` (draft) și `published_content`. Fluxul de publicare din UI este ⬜. |
| ARC-05 | Scrieri tranzacționale; polling calendar 15 s | 🟡 | Tranzacțiile sunt ✅ (`BookingService`). Polling-ul este configurat (`optica.booking.calendar_poll_seconds`); UI-ul calendarului este ⬜. |
| ARC-06 | API cu autorizare, paginare, validare; conflicte prin versiuni | 🟡 | `lock_version` pe programări, testat. API-ul HTTP este ⬜. |
| ARC-07 | Chei externe, indecși, migrații versionate, context istoric | ✅ | Migrații în `database/migrations`. Ștergerile sunt soft pentru specialiști, servicii și locații. |
| ARC-08 | Europe/Bucharest, testare la schimbarea orei, date demo fictive | ✅ | UTC în DB, conversie per dată (D-003). Testul `test_dst_change_keeps_local_hours` trece. |

## 3. Site public și CMS

| ID | Cerință | Status |
|---|---|---|
| WEB-01 | Refacerea site-ului cu identitatea actuală, fără informații inventate | ⬜ (identitatea este inventariată) |
| WEB-02 | Responsive, accesibil, reduced motion, acces rapid la programare | ⬜ |
| WEB-03 | Pagini administrabile pentru locații, specialiști, servicii; filtre, primul interval disponibil, hartă și listă, FAQ | 🟡 „Primul disponibil” este ✅ în motor (`AvailabilityService::firstAvailable`); UI ⬜ |
| WEB-04 | Elemente educative interactive, fără diagnostic | ⬜ |
| WEB-05 | Formulare → leaduri CRM, confirmare doar după salvare, antiabuz | ⬜ |
| CMS-01…03 | Pagini, secțiuni, meniuri, media, versiuni, previzualizare, publicare | ⬜ |
| SEO-01…02 | Metadate pe limbă, sitemap, hreflang, redirecturi 301, raport 404 | ⬜ (URL-urile de păstrat sunt inventariate) |

## 4. Locații, specialiști, programări online

| ID | Cerință | Status | Implementare |
|---|---|---|---|
| LOC-01 | Locații configurabile, program magazin separat de consultații, vizibilitate | 🟡 | Model `Location` cu `opening_hours` separat de `schedules`, `is_public`, `online_booking_enabled`. Administrarea din UI este ⬜. |
| LOC-02 | Specialist în mai multe locații; orare săptămânale, alternante, perioade, intervale multiple, pauze, concedii, sărbători, excepții | ✅ | `WorkingWindows`; teste pentru cicluri, concedii, sărbători, excepții parțiale și disponibilitate suplimentară. |
| LOC-03 | Durată per specialist, resurse, tampoane, timp de deplasare, capacitate implicit 1, online pe locație–specialist–serviciu | ✅ | `offerings` + `ConflictChecker`; teste pentru tampoane, resurse, deplasare și capacitate. |
| PRG-01 | Confirmare automată sau prin recepție; reguli de blocare și expirare | 🟡 | Statusuri `pending`/`confirmed`, expirare `hold_expires_at`, comanda `bookings:expire` ✅. Comutatorul per locație există (`confirmation_mode`), dar fluxul public este ⬜. |
| PRG-02 | Motor comun site–CRM; recepția blochează online; anularea eliberează | ✅ | Test T01 `test_online_and_reception_bookings_share_availability` și `test_cancellation_releases_the_slot`. |
| PRG-03 | Reverificare în tranzacție, expirarea rezervărilor temporare, idempotență, concurență cu durate diferite | ✅ | Blocare pesimistă (D-004). Teste cu procese paralele reale pe MariaDB. |
| PRG-04 | Reprogramare atomică; modificarea orarului nu șterge programări; limite min/max | 🟡 | Reprogramarea atomică și limitele min/max sunt ✅. Raportul programărilor afectate de modificarea orarului este ⬜. |
| PRG-05 | Linkuri de anulare/reprogramare neghicibile, API public doar disponibilitate, rate limiting | 🟡 | UUID per programare. Linkurile semnate și API-ul public sunt ⬜. |
| PRG-06 | Remindere după commit, invalidate la anulare, reverificare la expediere | 🟡 | Evenimentul `AppointmentChanged` este emis după commit (`DB::afterCommit`). Reminderele sunt ⬜. |

## 5. Calendar CRM și acces

| ID | Cerință | Status | Implementare |
|---|---|---|---|
| CAL-01 | Zi, săptămână, lună, agendă; coloane pe specialist/cabinet; filtre; mobil = agendă | ⬜ | |
| CAL-02 | Drag-and-drop cu validare, panou lateral, istoric, listă de așteptare | ⬜ | Validarea folosește `BookingService::reschedule`. |
| CAL-03 | Statusuri, tranziții controlate, motiv și autor la anulare, vizită ≠ programare | ✅ | `AppointmentStatus::allowedTransitions`, `appointment_events`, tabel `visits` separat. |
| ACC-01 | Permisiuni granulare, limitate pe locații și relația cu pacientul, refuz implicit | 🟡 | Refuz implicit și domeniu pe locații ✅ (`AccessControl`). Relația cu pacientul (policies) este ⬜. |
| ACC-02 | Autorizare pe server pentru obiect, API, fișier, export; jurnalizare | 🟡 | Gate-ul global este ✅; tabelul `audit_logs` există. Policies per model și jurnalizarea efectivă sunt ⬜. |

## 6–7. Pacienți, documente medicale, leaduri, comenzi, financiar

| ID | Status | Observații |
|---|---|---|
| PAC-01 | 🟡 | Tabel `patients`: CNP criptat + hash, limbă, reprezentant legal, relații de familie. UI ⬜. |
| PAC-02 | 🟡 | Telefon normalizat, neunic. Detectarea duplicatelor și unirea controlată sunt ⬜. |
| PAC-03 | ⬜ | |
| MED-01…05 | ⬜ | Șabloanele clinice trebuie validate de personal medical 📥. |
| Consimțăminte | ⬜ | Textele sunt validate de beneficiar 📥. |
| CRM-01…03 | ⬜ | |
| CMD-01…03 | ⬜ | |
| FIN-01…03 | ⬜ | |

## 8. Bilingvism și traduceri

| ID | Status | Implementare |
|---|---|---|
| LNG-01 | 🟡 | Fișiere `lang/ro` și `lang/hu` pentru modulele existente. Restul modulelor primesc traduceri pe măsură ce sunt construite. |
| LNG-02 | 🟡 | Limba se ia din contul angajatului (`users.locale`, middleware `staff.locale`). Testul de UI pentru T05 este ⬜. |
| LNG-03 | ✅ | Statusurile și permisiunile au identificatori stabili; etichetele sunt în fișierele de limbă. |
| TRD-01…02 | ⬜ | Editorul vizual. |
| TRD-03 | ⬜ | Centrul de traduceri (backend-ul de istoric și restaurare există). |
| TRD-04 | ✅ | `translation_overrides` aplicate peste fișiere, cu versiune de cache. Teste ✅. |
| TRD-05 | ✅ | Validarea variabilelor `:name` și `{name}`. Teste ✅. |

## 9. Comunicare

| ID | Status |
|---|---|
| COM-01…05, MKT-01…03, SMS-01, WAP-01 | ⬜ și ⚙️ (furnizorii și credențialele se stabilesc la audit) |

## 10. Rapoarte

| ID | Status |
|---|---|
| RAP-01…05 | ⬜. Datele necesare (sursă, creator, evenimente de reprogramare, vizite separate de programări, locația consultației) sunt deja în model. |

## 11. Securitate și exploatare

| ID | Status | Observații |
|---|---|---|
| SEC-01 | 🟡 | Parole hash; coloane 2FA; pachetul `pragmarx/google2fa` instalat. Fluxurile de autentificare sunt ⬜. |
| SEC-02 | 🟡 | Protecțiile Laravel (CSRF, escaping, interogări parametrizate) sunt active implicit. Documentele private sunt ⬜. |
| SEC-03…07 | ⬜ | |
| OPS-01 | 🟡 | Indecși pe interogările de disponibilitate. Măsurătorile de volum sunt ⬜. |
| OPS-02 | 🟡 | Heartbeat-ul cronului este ✅. Panoul de diagnostic este ⬜. |
| OPS-03 | ⬜ | |

## 12. Instalare și actualizări

| ID | Status | Observații |
|---|---|---|
| INS-01…02 | ⬜ | Proiectat în ARCHITECTURE.md §9. |
| UPD-01…06 | ⬜ | Proiectat în ARCHITECTURE.md §10 (semnătură Ed25519, D-009). |

## 13. Migrare

| ID | Status | Observații |
|---|---|---|
| MIG-01…05 | ⬜ / 📥 | Coloanele `external_ref` sunt pregătite pentru import repetabil. Așteptăm exporturile Evolvo autorizate. |

## 14. Teste de acceptanță

| ID | Status | Test automat |
|---|---|---|
| T01 | 🟡 nivel serviciu ✅, prin HTTP ⬜ | `BookingTest::test_online_and_reception_bookings_share_availability` |
| T02 | ✅ nivel serviciu | `ConcurrentBookingTest` (procese paralele reale) |
| T03 | ✅ nivel serviciu | `AvailabilityTest` (pauze, concedii, DST) + `BookingTest::test_reschedule_is_atomic` |
| T04 | 🟡 | `AccessControlTest` (refuz implicit, locații). API și fișiere ⬜. |
| T05 | 🟡 | Middleware ✅; testul de UI ⬜. |
| T06 | 🟡 backend ✅ | `TranslationOverridesTest`; editorul vizual ⬜. |
| T07–T12 | ⬜ | |

## Matrice Evolvo → platforma nouă (GEN-02)

| Funcție observată în Evolvo | Implementare proprie | Status |
|---|---|---|
| Program de lucru cu perioadă de valabilitate | `schedules.valid_from/valid_to` | ✅ |
| Alternare săptămânală, ciclu, „începe cu ciclul” | `cycle_weeks` + `cycle_anchor_date` | ✅ |
| Segmente zilnice (Dimineața … Suplimentar 2) | `schedule_segments.segment` | ✅ |
| Per segment: tip serviciu, resursă, durată, culoare, online | `service_ids`, `resource_id`, `slot_minutes`, `color`, `online_enabled` | ✅ (culoarea doar stocată; UI ⬜) |
| Zile nelucrătoare per persoană / locație / toate | `schedule_exceptions` cu domeniu nul = toate | ✅ |
| Programare online per punct de lucru (activ/inactiv, alias) | `locations.online_booking_enabled`, `content.alias` | 🟡 (UI ⬜) |
| Per medic × locație: info RO/HU, mesaj pauză, „programabil după”, durată, programări paralele, notificare | `location_specialist` (`content`, `online_paused`, `min_lead_minutes`, `max_advance_days`, `notify_on_online_booking`) + `offerings.capacity`, `duration_minutes` | ✅ în motor |
| Calendar săptămânal cu carduri colorate și popover | Calendar CRM | ⬜ |
| Status programare, dezactivare notificări, jurnal | `status`, `notifications_disabled`, `appointment_events` | ✅ model, UI ⬜ |
| Listă de așteptare | | ⬜ |
| Drepturi pe module (toate / fără) | Permisiuni pe acțiune + locații (mai granular) | ✅ |
| Gestionare utilizatori: rol, status, fotografie | `users`, `roles`, `specialists.photo_path` | 🟡 |
| Registrul pacienților, pacienți duplicați, pacienți noi | | ⬜ |
| Rapoarte (încasări, avansuri, datorii, statistici) | | ⬜ |
| Raport salarizare, cheltuieli/facturi parteneri | **Exclus** din livrarea de bază (cap. 1 „Condiții și limite”) | — |
