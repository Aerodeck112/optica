# Jurnalul versiunilor

Formatul urmează [Keep a Changelog](https://keepachangelog.com/ro/1.1.0/). Versiunile urmează SemVer.

## [0.5.0-dev] – 2026-10-19
### Adăugat
- Instalatorul web RO/HU (`/install`): token unic, verificarea serverului și a bazei de date, administrator, domeniu, fus orar, SMTP, linia de cron, token de recuperare, blocare după finalizare.
- Varianta pentru document root fix: `.htaccess` în rădăcina aplicației.
- Sistem → Backup: backupuri criptate și verificate, backup zilnic, descărcare, restaurare cu backup de siguranță.
- Sistem → Actualizări: pachete semnate Ed25519, verificări complete, backup, mentenanță, aplicare în pași, rollback automat, reluare, istoric.
- `/recovery.php`: recuperarea unei actualizări întrerupte fără aplicație.
- Sistem → Diagnostic: cron, coadă, comunicare, erori, spațiu, backup, actualizări.
- Sistem → Import date: pacienți și programări din CSV/XLSX, cu simulare, import repetabil și reluabil, reconciliere.
- Comenzi: `optica:release`, `optica:update-keygen`, `optica:update`, `optica:backup`, `optica:backup-decrypt`, `optica:recovery-token`.
- Permisiunea `system.import`; permisiunile noi ajung automat la rolurile implicite la actualizare.

### Reparat
- Încărcarea documentelor în dosarul pacientului nu funcționa în browser (numele acțiunii intra în conflict cu funcția de încărcare a Livewire).
- Programările marcate fără notificări (de exemplu cele importate) nu mai generează mesaje.

## [0.4.0-dev] – 2026-10-19
### Adăugat
- Registrul și dosarul pacientului: date personale, familie, reprezentant legal, CNP criptat afișat la cerere, programări și vizite, consimțăminte, istoric; arhivare; unirea controlată a duplicatelor.
- Fișe oftalmologice pe șabloane validate de personal medical: ciornă, finalizare, amendamente cu motiv, PDF RO/HU; date clinice criptate.
- Prescripții pentru ochelari și lentile de contact, cu versiuni și PDF.
- Documente private ale pacientului cu descărcare autorizată.
- Solicitările din site în CRM: istoric, note, responsabil, termen, conversie către pacient.
- Sarcini pe pacient, pe solicitare sau generale.
- Administrare → Șabloane de fișă; permisiunea nouă `medical_records.templates`.
- Comenzi de ochelari: articole, prescripție, statusuri cu istoric, comenzi întârziate, document de predare.
- Financiar operațional: servicii efectuate, încasări, avansuri, restituiri, anulări cu motiv, jurnal de încasări.
- Rapoarte: indicatori cu reguli de calcul, centralizator, matrice, ocupare, evoluție, distribuție, financiar, surse, drill-down; exporturi XLSX/CSV/PDF în fundal; rapoarte periodice pe email.
- Comunicare: canale email (SMTP), SMS (Twilio), WhatsApp (Cloud API); șabloane RO/HU; reguli de trimitere cu fallback; remindere; rechemări; jurnal de mesaje; webhookuri semnate; campanii cu dezabonare.
- Modul de traducere: textele afișate pe CRM și pe site se corectează direct pe pagină, în RO și HU, cu ciornă, publicare, istoric și revenire la textul standard.
- Administrare → Traduceri: centrul de traduceri cu căutare, filtre, export și import CSV/XLSX, publicarea ciornelor.

### Reparat
- Primul interval liber afișat pe site pentru fiecare locație se citea greșit din cache după 5 minute (eroare la afișarea paginii).

### Modificat
- Discul privat (`storage/app/private`) nu mai poate fi servit direct prin URL semnat; fișierele trec doar prin controllerul autorizat.

## [0.3.0-dev] – 2026-10-18
### Adăugat
- Administrarea utilizatorilor: roluri, locații, profil de specialist, invitații prin email (72 h) în limba angajatului, resetare 2FA, închiderea sesiunilor, protecția ultimului superadministrator.
- Administrarea rolurilor: matrice de permisiuni RO/HU, permisiuni clinice marcate, roluri personalizate; modificările de drepturi sunt jurnalizate.
- Serviciile oferite de fiecare specialist în fiecare locație (durată, capacitate, tampoane, preț, aparatură, online) și setările de programare online per locație.
- Cabinete și aparatură per locație.
- Orare de lucru cu săptămâni alternante și intervale pe zile; concedii, sărbători, închideri și program suplimentar; lista programărilor afectate de modificări.
- Calendarul CRM: zi, săptămână, lună, agendă; creare, mutare (inclusiv drag-and-drop), anulare cu motiv, schimbare status, istoric, listă de așteptare, actualizare automată.
- Registrul de pacienți (bază): cod intern, căutare, adăugare rapidă, sugestie de duplicate.
- `DemoDataSeeder` cu date fictive marcate DEMO.
- Programarea online pe site, în română și maghiară, cu rezervare temporară, consimțăminte și link de gestionare.
- Site-ul public construit din CMS, cu conținutul și imaginile site-ului actual, aceleași adrese, SEO (sitemap, hreflang, canonical), redirecționări pentru adresele vechi și jurnal 404.
- Formularele site-ului creează leaduri cu consimțăminte.
- Adminul CMS: pagini pe secțiuni cu ciornă, previzualizare, publicare, versiuni și protecție la editare simultană; bibliotecă de imagini; meniuri; oferte cu perioadă de valabilitate; redirecționări și raport 404; datele de contact ale site-ului.

### Modificat
- Mesajele de validare afișează denumirile traduse ale câmpurilor.
- Adresele publice nu mai au „/” la final; varianta veche răspunde cu 301 (D-012).
- Eliminat `public/robots.txt` static: `robots.txt` este generat de aplicație și indică sitemap-ul.

## [0.2.0-dev] – 2026-10-17
### Adăugat
- Date reale de pornire de pe site-ul actual: 8 locații și 12 servicii RO/HU, telefoanele de programare și cifrele confirmate de beneficiar.
- Autentificarea personalului: login cu limitare a încercărilor, verificare în doi pași (TOTP) obligatorie pentru rolurile privilegiate, resetare parolă, jurnal de securitate.
- Layout CRM responsive, în identitatea Optica Optofarm (albastru #004975, galben #FFDF51, Inter găzduit local), cu limbă RO/HU per angajat; datele nesalvate se păstrează la schimbarea limbii.
- Administrare locații, medici și optometriști, servicii: editare RO/HU alăturată, ciornă și publicare, ștergere protejată de programările viitoare, restaurare, fotografii re-encodate.
- Comanda `optica:create-admin`.
- Mesaje de validare, autentificare și email de resetare în română și maghiară.

### Modificat
- Fișierele publice (de exemplu fotografiile specialiștilor) se salvează în `public/uploads`, fără link simbolic.

## [0.1.0-dev] – 2026-10-17
### Adăugat
- Proiect Laravel 13 configurat pentru MySQL/MariaDB, limba română implicită și UTC în baza de date (ora clinicilor: Europe/Bucharest).
- Model de date pentru locații, resurse, specialiști, servicii, oferte (locație–specialist–serviciu), orare cu săptămâni alternante, segmente, excepții, pacienți, programări, jurnalul programărilor și vizite.
- Motor de disponibilitate comun site–CRM: concedii, sărbători, ore de vară/iarnă, tampoane, resurse, timp de deplasare între locații, capacitate.
- Rezervare tranzacțională cu blocare pe specialist și resurse, idempotență, reprogramare atomică, statusuri controlate, rezervări temporare cu expirare.
- Permisiuni cu refuz implicit, domeniu pe locații și roluri inițiale conform caietului de sarcini.
- Corecturi de traducere RO/HU în baza de date, cu draft, publicare, istoric și protecția variabilelor.
- Sarcini cron: heartbeat, expirarea rezervărilor, procesarea cozii.
- Documentație: REQUIREMENTS, ARCHITECTURE, PERMISSIONS, PROGRESS, DECISIONS, DEPLOYMENT; analiza site-ului actual și a fotografiilor Evolvo.
- CI GitHub Actions cu MariaDB.

### Eliminat
- Workflow-ul GitHub Pages pentru Gatsby, care nu se aplica proiectului.
