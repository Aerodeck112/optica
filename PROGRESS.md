# Progres

Ultima actualizare: 18 octombrie 2026. Versiune: `0.4.0-dev`.

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
| Date demonstrative | `DemoDataSeeder`: specialiști, pacienți și programări fictive marcate DEMO; utilizatori demo (admin operațional, recepție, editor site); refuză să ruleze în producție | rulat manual |
| Programare online (RO/HU) | `/programare`: locație → serviciu → specialist sau „primul disponibil” → dată și oră → date de contact → confirmare; rezervare temporară 10 min; confirmare automată sau de către recepție după setarea locației; consimțăminte separate (marketing nebifat); link de gestionare pentru anulare și reprogramare; specialiști în pauză cu mesajul lor; limitare pe IP | `OnlineBookingTest` (9) |
| Site public RO/HU | pagini construite din secțiuni, cu conținutul și imaginile site-ului actual; servicii, puncte de lucru (cu primul interval liber), oferte; elemente educative fără diagnostic; aceleași adrese ca site-ul actual, adrese canonice fără „/” final (301) | `PublicSiteTest` (10) + capturi |
| SEO | titlu, descriere, noindex per limbă, canonical, hreflang, sitemap.xml, robots.txt; redirecționări 301/302/410 pentru adresele WordPress vechi; jurnal 404 | `PublicSiteTest`, `CmsAdminTest` |
| Formulare → leaduri | formularul de contact salvează leadul și consimțămintele înainte de confirmare; fără duplicate la dublu clic; honeypot și limitare | `PublicSiteTest` |
| CMS → Pagini | 12 tipuri de secțiuni RO/HU alăturate, reordonare, ciornă, previzualizare RO/HU, publicare cu versiuni numerotate, readucerea unei versiuni în ciornă, renunțarea la modificări, protecție la editare simultană, adrese și SEO doar cu dreptul `seo.manage`, 301 automat la schimbarea adresei, pagini de bază nedeletabile | `CmsAdminTest` (14) |
| CMS → Imagini | încărcare multiplă, re-encodare WebP fără metadate, text alternativ RO/HU, filtrul „fără text alternativ”, lista utilizărilor, ștergere blocată pentru imaginile folosite | `CmsAdminTest` |
| CMS → Meniuri, oferte, redirecționări, date de contact | meniul principal și subsolul (drept de publicare); oferte cu perioadă de valabilitate, ciornă, publicare, retragere; redirecționări cu detectarea buclelor și crearea lor din raportul 404; telefoanele și emailurile afișate pe site | `CmsAdminTest` |

| CRM → Pacienți | registru cu căutare (nume, telefon în orice format, cod, email), pacient nou cu avertizare de duplicate, dosar cu taburi: date personale, familie și reprezentant legal, CNP criptat afișat la cerere, programări și vizite, fișe, prescripții, documente, consimțăminte, sarcini, istoric cronologic; arhivare; unirea controlată a duplicatelor | `PatientFileTest`, `PatientDataTest` |
| Fișe oftalmologice | șabloane versionate validate de personal medical, ciornă cu protecție la editare simultană, valori verificate (interval și pas, virgulă acceptată), finalizare cu câmpuri obligatorii, amendamente cu motiv și valorile vechi/noi, PDF RO/HU, date criptate, acces după relația cu pacientul | `ClinicalRecordsTest` (7), `PatientFileTest` |
| Prescripții | ochelari și lentile de contact, OD/OS, verificarea intervalelor, versiuni la corectură cu motiv, copierea refracției scrise în fișă (fără calcule), PDF RO/HU | `PatientDataTest`, `PatientFileTest` |
| Documente private | PDF și imagini, tip verificat după conținut, stocare în afara directorului public, descărcare autorizată și jurnalizată | `PatientDataTest`, `PatientFileTest` |
| CRM → Solicitări | listă cu filtre (status, tip, responsabil), istoric, note interne, responsabil și termen, conversie către pacient existent sau nou, consimțămintele trec la pacient | `PatientDataTest`, `PatientFileTest` |
| Sarcini | pe pacient, pe solicitare sau generale; responsabil, termen (ora clinicii), urgent, finalizare și redeschidere; liste „ale mele” și „locațiile mele” | `PatientFileTest` |
| Administrare → Șabloane de fișă | editare ca ciornă (câmpuri, secțiuni, unități, intervale, OD/OS, obligatorii), validare jurnalizată, versiuni noi, retragere | `PatientFileTest` |

| Comenzi de ochelari | articole cu preț, prescripție legată (valorile vizibile doar celor autorizați), responsabil, termen, statusuri controlate cu istoric, predare cu sold doar cu confirmare, lista întârziatelor, document de predare PDF, acces pe locații | `LedgerTest`, `OrdersScreensTest` |
| Financiar operațional | servicii efectuate (preț propus din configurare), încasări, avansuri, restituiri, anulări cu motiv, sold pe comandă, serviciu și pacient, jurnal de încasări pe perioadă și locație cu totaluri pe metode, separat de valoarea serviciilor și a comenzilor predate; protecție la dublu clic | `LedgerTest` (8), `OrdersScreensTest` (5) |

| Comunicare | conturi email (SMTP), SMS (Twilio), WhatsApp (Cloud API) cu credențiale criptate și stare „neconfigurat”; test real către destinatarul introdus (WhatsApp: verificarea conexiunii); șabloane RO/HU cu variabile validate, previzualizare cu date fictive, segmente SMS; reguli pe tip și locație cu ordinea canalelor; coadă prin cron cu reverificare, limite pe minut, interval de liniște; webhookuri semnate; jurnal cu stări reale, cost estimat și raportat, retrimitere manuală; tab Comunicări în dosar cu rechemare la control | `MessagingTest` (10), `CommunicationScreensTest` (7) |
| Mesaje operaționale | cerere primită, confirmare, mutare, anulare, reminder (retras la schimbări), comandă gata, rechemare – fără detalii medicale; șabloane implicite RO/HU, reguli inițial dezactivate | `MessagingTest` |
| Campanii | email și SMS; segmente după limbă și locație; doar pacienți cu acord; test; programare, pauză, reluare, oprire; reverificarea acordului și a limitei de frecvență la trimitere; dezabonare cu link semnat și STOP | `MessagingTest`, `CommunicationScreensTest` |

Total: **166 de teste, toate trec** (`php artisan test`).

## Neconfigurat (necesită date externe)
- Conturile reale de email (SMTP), SMS (Twilio sau alt furnizor, după audit) și WhatsApp Business (Meta), plus aprobarea șabloanelor WhatsApp (COM-01, WAP-01). Până atunci, canalele apar „neconfigurate” și nu se trimite nimic.
- Hostingul cPanel: versiunea PHP, extensiile, cronul și document root trebuie verificate (INS-02, D-001).

## În așteptarea datelor de la beneficiar
- Medicii și optometriștii: se introduc din Admin → Medici și optometriști (decizia N-06).
- Orarele de consultații, duratele, resursele, capacitățile și modul de confirmare per locație.
- Tarifele (dacă se publică).
- Exporturile autorizate din Evolvo (MIG-01) – la finalul proiectului, conform deciziei beneficiarului.
- Textele legale și de consimțământ validate (SEC-05).
- Validarea șablonului de fișă oftalmologică de către personal medical (MED-01, D-016): până atunci, fișele nu pot fi completate.
- Traducerea maghiară a paragrafului „Știai că…” (pagina Medici) și versiunea HU a textelor legale (N-08).

## Neimplementat încă (ordinea planificată)
1. Rapoarte avansate și exporturi (Excel, CSV, PDF).
2. Editorul vizual de traduceri și centrul de traduceri.
3. Instalatorul web, actualizatorul ZIP semnat, backup/restaurare, panoul de diagnostic, importuri.
4. Import din Evolvo (la final).
5. Testele de acceptanță T01–T12 la nivel HTTP/UI, inclusiv lățimile 390 / 768 / 1440 px.
