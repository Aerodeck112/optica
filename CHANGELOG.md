# Jurnalul versiunilor

Formatul urmează [Keep a Changelog](https://keepachangelog.com/ro/1.1.0/). Versiunile urmează SemVer.

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
