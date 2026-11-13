# Casa de marcat — agentul local

**Stare: adaptor pregătit; driver pentru Daisy prin Fisco adăugat (verificat cu o imitație a API-ului Fisco).** Fluxul server ↔ agent este verificat automat și a fost rulat cap-coadă cu un aparat simulat.
Nu a fost testat cu o casă de marcat reală; conectarea finală se face împreună cu service-ul autorizat al aparatului.

## Cum funcționează

1. La recepție, la o încasare în numerar sau cu cardul la POS, aplicația pune bonul într-o coadă (Financiar → Bonuri fiscale).
2. Agentul de pe calculatorul conectat la casa de marcat întreabă serverul la câteva secunde dacă există bonuri.
3. Agentul transmite bonul programului aparatului și raportează rezultatul (tipărit, cu numărul bonului, sau eșuat).
4. Dacă agentul preia un bon dar nu raportează nimic în 90 de secunde, bonul devine **„Incert — de verificat”**.
   Aplicația **nu îl retrimite** (s-ar putea tipări de două ori). Contabilul sau managerul verifică în jurnalul electronic
   al aparatului / pe raportul Z și alege *Reconciliază* → „tipărit” (cu numărul) sau „netipărit”; doar în al doilea caz
   se poate reemite.

## Pachetul pentru Windows (fără instalare PHP)

`tools/fiscal-agent/windows/` conține fișierele ajutătoare (`1-PROBA.bat`, `2-PORNESTE-AGENTUL.bat`, `3-PORNIRE-AUTOMATA.bat`,
`4-OPRESTE-PORNIREA-AUTOMATA.bat`, `php.ini`, `CITESTE-MA.txt`). Pachetul „AW-Agent-Casa” se face punând lângă ele agentul și
un PHP portabil oficial (windows.php.net, 8.3 NTS x64, verificat cu suma SHA-256 publicată) în subfolderul `php\`, plus
`cacert.pem` (curl.se) pentru HTTPS. PHP pentru Windows cere „Microsoft Visual C++ Redistributable x64”, de obicei deja instalat.

## Instalare pe calculatorul de la recepție

Cerințe: PHP 8.1+ cu extensia `curl` (pe Windows: pachetul oficial PHP, adăugat în PATH).

1. Panou → **Financiar → Case de marcat** → adăugați aparatul (mod *Agent local*), completați corespondența cotelor TVA →
   grupe fiscale și codurile metodelor de plată, primite de la service.
2. Butonul **Cheie agent** → copiați cheia și secretul (secretul se afișează o singură dată).
3. Copiați `tools/fiscal-agent/aw-fiscal-agent.php` și `aw-fiscal-agent.ini.example` într-un folder (ex. `C:\aw-agent`),
   redenumiți exemplul în `aw-fiscal-agent.ini` și completați-l.
4. Probă fără tipărire: `DRIVER=dry-run`, apoi `php aw-fiscal-agent.php --once`. În panou, aparatul apare „văzut acum”.
5. Pornire permanentă: o sarcină programată Windows (*Task Scheduler*, „la pornirea calculatorului”) care rulează
   `php C:\aw-agent\aw-fiscal-agent.php`.

## Daisy Compact M (prin programul Fisco)

Legătura PC ↔ Daisy o face programul **Fisco** (fisco.ro, licență permanentă legată de seria casei, 30 de zile de probă).
Agentul îi trimite bonul prin API-ul local al programului (`http://127.0.0.1:4040`), în sintaxa simplificată Fisco:
`S^Denumire^preț_bani^cantitate_miimi^buc^grupa^1` pentru fiecare linie și `P^cod^sumă_bani` pentru plată.
Cheia de idempotență este id-ul comenzii, deci o retrimitere după un răspuns pierdut nu tipărește un al doilea bon.

1. Casa este **fiscalizată** de service-ul autorizat (obligatoriu înainte de primul bon).
2. Pe calculatorul de la recepție: driverul USB (VCP) al casei, apoi Fisco; în Fisco se alege modelul Daisy și portul COM.
3. Casa se trece în modul PC (imprimantă fiscală) — conform manualului / service-ului (la Compact M, de regulă tasta
   **M** până apare „PC”, parola de operator, **CLK**).
4. Proba din Fisco: un bon de test de valoare mică, apoi anulat conform procedurii service-ului.
5. Panou → **Financiar → Case de marcat**: aparat nou, mod *Agent local*, model „Daisy Compact M 01”, seria fiscală;
   cote TVA → grupe (de pe raportul X al casei, ex. `21 → A`, `11 → B`); plăți: `cash → 1`, `card_pos → 2`;
   **Cheie agent** (se copiază cheia și secretul).
6. Agentul: în `aw-fiscal-agent.ini` → `DRIVER=fisco`, `FISCO_URL=http://127.0.0.1:4040`, cheia și secretul;
   probă `php aw-fiscal-agent.php --once`, apoi pornire automată (Task Scheduler).
7. Proba finală: o încasare reală mică din panou cu „Emite bon fiscal” → bonul se tipărește, iar în
   **Financiar → Bonuri fiscale** apar numărul bonului și numărul raportului Z.

Verificat cu o imitație a API-ului Fisco (tipărit, eroare raportată de aparat, Fisco oprit, repornirea agentului);
**nu** a fost verificat cu Fisco și o casă Daisy reale.

## Driverul `file`

Agentul scrie fiecare bon ca fișier JSON în `FILE_OUT_DIR` și așteaptă răspunsul `{id}.result.json` în `FILE_IN_DIR`:

```json
{"status": "printed", "fiscal_number": "0001234", "z_report_number": "0101"}
```

Programul care traduce acest JSON în comenzile aparatului diferă de la un producător la altul (de regulă, producătorii
oferă un utilitar care preia fișiere dintr-un folder). Această „punte” se configurează cu service-ul aparatului.
Formatul comenzii conține: articolele (denumire ≤ 36 caractere, cantitate, preț, cota TVA și grupa fiscală mapată),
plățile (metoda și codul mapat) și totalul.

## Siguranță

* Agentul notează fiecare comandă în `journal.json` **înainte** de a o trimite la aparat; la repornire nu retipărește
  comenzile din jurnal.
* Rezultatele tipărite dar neconfirmate de server (ex. internet căzut) se retrimit automat la următoarea conexiune.
* `aw-fiscal-agent.ini` și `journal.json` conțin date sensibile și nu se copiază pe alte calculatoare.
