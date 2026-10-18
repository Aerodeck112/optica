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
| GEN-01 | Inventar pagini RO/HU, URL-uri, imagini, logo, culori, fonturi, locații, servicii | ✅ | Inventar în `docs/analiza-site.md`; resursele sunt importate în CMS. |
| GEN-02 | Matrice funcții Evolvo → implementare proprie | 🟡 | `docs/analiza-evolvo.md` + matricea de mai jos. |
| GEN-03 | Fără date personale din capturi; aplicație independentă de Evolvo | ✅ | Fotografiile nu sunt în repository. Testele și datele demonstrative folosesc persoane fictive. |
| GEN-04 | Pagini de referință; 8 locații reverificate | ✅ | Beneficiarul a rezolvat N-01…N-07 (DECISIONS.md). Cele 8 locații și 12 servicii sunt importate RO/HU (`SiteContentSeeder`). |

## 2. Arhitectură și date

| ID | Cerință | Status | Implementare |
|---|---|---|---|
| ARC-01 | PHP modular, Laravel, MySQL/MariaDB, server-rendered reactiv | ✅ | Laravel 13, MariaDB 10.11, Blade + Livewire 4; CSS compilat (Tailwind) inclus în `public/build`. |
| ARC-02 | Fără Node, Docker, Redis, WebSocket sau SSH în producție; cozi DB prin cron cu blocare | ✅ | `routes/console.php`: un singur cron, coadă `database`, `withoutOverlapping`. |
| ARC-03 | Rute `/`, `/programare`, `/crm`, `/admin` | 🟡 | `/crm` (autentificare, panou) și `/admin` (catalog) există. `/` este provizoriu. `/programare` este ⬜. |
| ARC-04 | Identificatori stabili, o singură sursă de adevăr, draft–publicare | ✅ | Locațiile, specialiștii și serviciile se editează ca ciornă și se publică din admin. Site-ul va citi doar `published_content`. |
| ARC-05 | Scrieri tranzacționale; polling calendar 15 s | ✅ | Tranzacții în `BookingService`; calendarul se actualizează la 15 s (configurabil) și afișează ora ultimei actualizări și starea conexiunii. |
| ARC-06 | API cu autorizare, paginare, validare; conflicte prin versiuni | 🟡 | `lock_version` pe programări, testat. API-ul HTTP este ⬜. |
| ARC-07 | Chei externe, indecși, migrații versionate, context istoric | ✅ | Migrații în `database/migrations`. Ștergerile sunt soft pentru specialiști, servicii și locații. |
| ARC-08 | Europe/Bucharest, testare la schimbarea orei, date demo fictive | ✅ | UTC în DB, conversie per dată (D-003). Testul `test_dst_change_keeps_local_hours` trece. |

## 3. Site public și CMS

| ID | Cerință | Status |
|---|---|---|
| WEB-01 | Refacerea site-ului cu identitatea actuală, fără informații inventate | ✅ Conținutul, imaginile, locațiile și serviciile site-ului actual sunt importate în CMS (`CmsContentSeeder`, `SiteContentSeeder`). |
| WEB-02 | Responsive, accesibil, reduced motion, acces rapid la programare | ✅ Verificat la 390/768/1440 px; butonul de programare și telefoanele sunt în antet. Auditul WCAG complet rămâne la livrare. |
| WEB-03 | Pagini administrabile pentru locații, specialiști, servicii; filtre, primul interval disponibil, hartă și listă, FAQ | 🟡 Administrarea RO/HU (adăugare, editare, publicare, ștergere, restaurare) este ✅. „Primul disponibil” este ✅ în motor. Paginile publice (listă, detaliu, filtre, primul interval liber, hartă) sunt ✅. |
| WEB-04 | Elemente educative interactive, fără diagnostic | ✅ Comparația vedere încețoșată/clară și ghidul „ce consultație mi se potrivește”, cu mențiunea că nu înlocuiesc consultația. |
| WEB-05 | Formulare → leaduri CRM, confirmare doar după salvare, antiabuz | ✅ Leadul și consimțămintele se salvează înainte de mesajul de confirmare; idempotență, honeypot, limitare. Leadurile se lucrează în CRM → Solicitări (CRM-01). |
| CMS-01…03 | Pagini, secțiuni, meniuri, media, versiuni, previzualizare, publicare | ✅ Pagini din 12 tipuri de secțiuni RO/HU, ciornă, previzualizare RO/HU, publicare cu versiuni și restaurare, protecție la editare simultană, meniuri, bibliotecă de imagini cu text alternativ și verificarea utilizării, oferte cu perioadă de valabilitate. |
| SEO-01…02 | Metadate pe limbă, sitemap, hreflang, redirecturi 301, raport 404 | ✅ Titlu/descriere/noindex per limbă, canonical, hreflang, sitemap.xml, robots.txt, redirecționări 301/302/410 cu detectarea buclelor, raport 404, adresele vechi WordPress redirecționate. |

## 4. Locații, specialiști, programări online

| ID | Cerință | Status | Implementare |
|---|---|---|---|
| LOC-01 | Locații configurabile, program magazin separat de consultații, vizibilitate | ✅ | Admin → Locații: date RO/HU, contact, coordonate, programul magazinului, vizibilitate, programare online, mod de confirmare. Fotografiile locațiilor se aleg din biblioteca de imagini. |
| LOC-02 | Specialist în mai multe locații; orare săptămânale, alternante, perioade, intervale multiple, pauze, concedii, sărbători, excepții | ✅ | `WorkingWindows`; teste pentru cicluri, concedii, sărbători, excepții parțiale și disponibilitate suplimentară. |
| LOC-03 | Durată per specialist, resurse, tampoane, timp de deplasare, capacitate implicit 1, online pe locație–specialist–serviciu | ✅ | `offerings` + `ConflictChecker`; teste pentru tampoane, resurse, deplasare și capacitate. |
| PRG-01 | Confirmare automată sau prin recepție; reguli de blocare și expirare | ✅ | Statusuri `pending`/`confirmed`, rezervare temporară de 10 min, expirare prin `bookings:expire`; fluxul public respectă `confirmation_mode` al locației (`OnlineBookingTest`). |
| PRG-02 | Motor comun site–CRM; recepția blochează online; anularea eliberează | ✅ | Test T01 `test_online_and_reception_bookings_share_availability` și `test_cancellation_releases_the_slot`. |
| PRG-03 | Reverificare în tranzacție, expirarea rezervărilor temporare, idempotență, concurență cu durate diferite | ✅ | Blocare pesimistă (D-004). Teste cu procese paralele reale pe MariaDB. |
| PRG-04 | Reprogramare atomică; modificarea orarului nu șterge programări; limite min/max | ✅ | Reprogramare atomică, limite min/max configurabile per specialist × locație, raportul programărilor afectate (`AffectedAppointments`) la orare și excepții. |
| PRG-05 | Linkuri de anulare/reprogramare neghicibile, API public doar disponibilitate, rate limiting | ✅ | Link de gestionare cu token de 192 biți (în baza de date doar hash-ul), `/api/disponibilitate` returnează doar intervale, limitare pe IP (`OnlineBookingTest`). |
| PRG-06 | Remindere după commit, invalidate la anulare, reverificare la expediere | 🟡 | Evenimentul `AppointmentChanged` este emis după commit (`DB::afterCommit`). Reminderele sunt ⬜. |

## 5. Calendar CRM și acces

| ID | Cerință | Status | Implementare |
|---|---|---|---|
| CAL-01 | Zi, săptămână, lună, agendă; coloane pe specialist/cabinet; filtre; mobil = agendă | ✅ | `Crm\Calendar`: coloane pe specialist (cabinetul apare în orar și pe programare), filtre locație/specialist/status, agenda implicită sub 768 px, căutare pacient și creare din interval. |
| CAL-02 | Drag-and-drop cu validare, panou lateral, istoric, listă de așteptare | ✅ | Drag-and-drop cu confirmare și formular alternativ, panou lateral cu istoric, culori însoțite de etichete, timpul din alte locații vizibil, listă de așteptare cu propuneri la anulare. |
| CAL-03 | Statusuri, tranziții controlate, motiv și autor la anulare, vizită ≠ programare | ✅ | `AppointmentStatus::allowedTransitions`, `appointment_events`, tabel `visits` separat. |
| ACC-01 | Permisiuni granulare, limitate pe locații și relația cu pacientul, refuz implicit | ✅ | Refuz implicit, domeniu pe locații, roluri personalizabile; `ClinicalAccess` aplică relația specialist–pacient pentru fișe și prescripții (`ClinicalRecordsTest`, `PatientFileTest`). |
| ACC-02 | Autorizare pe server pentru obiect, API, fișier, export; jurnalizare | ✅ | Verificare pe server la fiecare cerere Livewire, la fiecare fișier și PDF. Se jurnalizează autentificările, deschiderea dosarelor și fișelor, afișarea CNP-ului, descărcările, exporturile PDF și modificările de drepturi. Exporturile Excel/CSV vin cu modulul de rapoarte. |

## 6–7. Pacienți, documente medicale, leaduri, comenzi, financiar

| ID | Status | Observații |
|---|---|---|
| PAC-01 | ✅ | CRM → Pacienți: cod intern, date personale, limbă, adresă, sursă, reprezentant legal, relații de familie; CNP criptat, afișat doar la cerere cu dreptul `patients.view_sensitive` și jurnalizat. |
| PAC-02 | ✅ | Telefon neunic; posibilele duplicate se arată înainte de salvare. Unirea se face manual, după comparare și confirmare, mută toate referințele și se jurnalizează (D-017). |
| PAC-03 | 🟡 | Taburi: date personale, programări și vizite, fișe, prescripții, comenzi, financiar, documente, consimțăminte, sarcini, istoric cronologic. Tabul Comunicări apare cu modulul de comunicare; exportul dosarului vine cu rapoartele. |
| MED-01…05 | ✅ / 📥 | Șabloane versionate cu unități, intervale și pași, utilizabile doar după validarea de către personal medical (D-016) 📥; ciornă → finalizare → amendamente cu autor, oră, motiv și valorile vechi/noi; prescripții cu autor, dată și versiune; PDF RO/HU cu antet, locație și specialist, observații netraduse, fără pretenția de semnătură calificată; documente private verificate după conținut (D-018). Aplicația nu calculează diagnostice sau prescripții. |
| Consimțăminte | ✅ / 📥 | Informare, condiții și marketing pe canal, cu versiune, dată, sursă și istoric; acordul sau retragerea se înregistrează și la recepție; consimțămintele din formulare trec la pacient la conversie. Textele și retenția se validează de beneficiar 📥. |
| CRM-01…02 | ✅ | CRM → Solicitări: tip, limbă, sursă și UTM, locație, responsabil, termen, istoric, note, sarcini; statusuri nou/contactat/în lucru/convertit/închis; conversia caută întâi pacientul existent. |
| CRM-03 | 🟡 | UTM-urile din formular se păstrează; nu există pixeli publicitari. Atribuirea campaniilor vine cu rapoartele. |
| CMD-01…03 | ✅ / ⚙️ | CRM → Comenzi ochelari: pacient, locație, responsabil, prescripție (valorile doar pentru cei autorizați), articole (ramă, lentile, tratamente), preț, avans, sold, termen; statusuri cu autor și oră; lista întârziatelor; istoric; document de predare PDF; legătura cu consultația. Notificarea „gata de ridicare” este emisă ca eveniment și se trimite odată cu modulul de comunicare ⚙️. |
| FIN-01…03 | ✅ | Valoarea serviciilor și a comenzilor separată de încasări; plăți, avansuri, restituiri cu dată, metodă, locație și operator; corecturile anulează cu motiv, fără ștergere; sume în bani întregi; avansul nu se numără de două ori; nicio pretenție de document fiscal (D-019). |

## 8. Bilingvism și traduceri

| ID | Status | Implementare |
|---|---|---|
| LNG-01 | 🟡 | Fișiere `lang/ro` și `lang/hu` pentru modulele existente. Restul modulelor primesc traduceri pe măsură ce sunt construite. |
| LNG-02 | ✅ | Limba e salvată în contul angajatului; comutatorul RO/HU revine la aceeași pagină și filtre. Datele nesalvate din formulare se păstrează (verificat în browser). Teste `StaffLocaleTest`. |
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
| SEC-01 | 🟡 | Login cu limitare a încercărilor și mesaj generic, 2FA TOTP obligatoriu pentru superadmin / admin operațional / manager / financiar, resetare parolă cu token expirabil, blocarea imediată a conturilor dezactivate. Teste `LoginTest`. Expirarea sesiunilor inactive și revocarea sesiunilor din admin sunt ⬜. |
| SEC-02 | 🟡 | CSRF, escaping și interogări parametrizate active; documentele private sunt în afara directorului public, verificate după conținut, descărcate doar prin controller autorizat. Antetele de securitate globale (CSP, HSTS) se configurează la instalare. |
| SEC-03 | 🟡 | Imaginile încărcate sunt re-encodate (metadate eliminate) și nu se pot executa. Jurnalele nu conțin parole. |
| SEC-04…07 | ⬜ | |
| OPS-01 | 🟡 | Indecși pe interogările de disponibilitate. Măsurătorile de volum sunt ⬜. |
| OPS-02 | 🟡 | Heartbeat-ul cronului este ✅. Panoul de diagnostic este ⬜. |
| OPS-03 | 🟡 | Ecranele CRM existente: verificate la 390/768/1440 px, etichete și erori asociate câmpurilor, confirmare înainte de ștergere. |

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
| T01 | ✅ nivel serviciu și flux public | `BookingTest::test_online_and_reception_bookings_share_availability` |
| T02 | ✅ nivel serviciu | `ConcurrentBookingTest` (procese paralele reale) |
| T03 | ✅ nivel serviciu | `AvailabilityTest` (pauze, concedii, DST) + `BookingTest::test_reschedule_is_atomic` |
| T04 | 🟡 | `AccessControlTest` + `CatalogAdminTest` (pagină și componentă refuzate fără permisiune). API, fișiere și exporturi ⬜. |
| T05 | ✅ | `StaffLocaleTest` + verificare în browser a păstrării datelor nesalvate. |
| T06 | 🟡 backend ✅ | `TranslationOverridesTest`; editorul vizual ⬜. |
| T07–T11 | ⬜ | |
| T12 | 🟡 | Ecranele CRM existente sunt verificate la 390, 768 și 1440 px, fără derulare orizontală. Site-ul public este ⬜. |

## Matrice Evolvo → platforma nouă (GEN-02)

| Funcție observată în Evolvo | Implementare proprie | Status |
|---|---|---|
| Program de lucru cu perioadă de valabilitate | `schedules.valid_from/valid_to` | ✅ |
| Alternare săptămânală, ciclu, „începe cu ciclul” | `cycle_weeks` + `cycle_anchor_date` | ✅ |
| Segmente zilnice (Dimineața … Suplimentar 2) | `schedule_segments.segment` | ✅ |
| Per segment: tip serviciu, resursă, durată, culoare, online | `service_ids`, `resource_id`, `slot_minutes`, `color`, `online_enabled` | ✅ (culoarea doar stocată; UI ⬜) |
| Zile nelucrătoare per persoană / locație / toate | `schedule_exceptions` cu domeniu nul = toate | ✅ |
| Programare online per punct de lucru (activ/inactiv, alias) | `locations.online_booking_enabled`, denumire publică RO/HU | ✅ |
| Per medic × locație: info RO/HU, mesaj pauză, „programabil după”, durată, programări paralele, notificare | `location_specialist` (`content`, `online_paused`, `min_lead_minutes`, `max_advance_days`, `notify_on_online_booking`) + `offerings.capacity`, `duration_minutes` | ✅ în motor |
| Calendar săptămânal cu carduri colorate și popover | Calendar CRM (zi/săptămână/lună/agendă, panou lateral) | ✅ |
| Status programare, dezactivare notificări, jurnal | `status`, `notifications_disabled`, `appointment_events`; istoric în calendar | ✅ (comutatorul de notificări vine cu modulul de comunicare) |
| Listă de așteptare | `waitlist_entries` + panou în calendar | ✅ |
| Drepturi pe module (toate / fără) | Permisiuni pe acțiune + locații (mai granular) | ✅ |
| Gestionare utilizatori: rol, status, fotografie | Admin → Utilizatori și Roluri, fotografia pe profilul de specialist | ✅ |
| Registrul pacienților, pacienți duplicați, pacienți noi | | ⬜ |
| Rapoarte (încasări, avansuri, datorii, statistici) | | ⬜ |
| Raport salarizare, cheltuieli/facturi parteneri | **Exclus** din livrarea de bază (cap. 1 „Condiții și limite”) | — |
