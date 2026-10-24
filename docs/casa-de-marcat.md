# Casa de marcat — agentul local

**Stare: adaptor pregătit.** Fluxul server ↔ agent este verificat automat și a fost rulat cap-coadă cu un aparat simulat.
Nu a fost testat cu o casă de marcat reală; conectarea finală se face împreună cu service-ul autorizat al aparatului.

## Cum funcționează

1. La recepție, la o încasare în numerar sau cu cardul la POS, aplicația pune bonul într-o coadă (Financiar → Bonuri fiscale).
2. Agentul de pe calculatorul conectat la casa de marcat întreabă serverul la câteva secunde dacă există bonuri.
3. Agentul transmite bonul programului aparatului și raportează rezultatul (tipărit, cu numărul bonului, sau eșuat).
4. Dacă agentul preia un bon dar nu raportează nimic în 90 de secunde, bonul devine **„Incert — de verificat”**.
   Aplicația **nu îl retrimite** (s-ar putea tipări de două ori). Contabilul sau managerul verifică în jurnalul electronic
   al aparatului / pe raportul Z și alege *Reconciliază* → „tipărit” (cu numărul) sau „netipărit”; doar în al doilea caz
   se poate reemite.

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
