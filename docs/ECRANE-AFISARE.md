# Ecrane afișare — instalare și operare

Două monitoare în arenă, fiecare cu PC-ul lui și un browser deschis permanent pe o adresă dedicată:

| Ecran | Adresă (deschisă pe PC-ul monitorului) | Ce arată |
|---|---|---|
| Ecran 1 — **Live Arena** | `https://anotherworldcluj.ro/display/arena-live` | sesiunea pornită de operator: jocul, echipa (nickname + avatar), timpul rămas, runda următoare; la final „Misiune încheiată” și podiumul (doar din rezultate reale) |
| Ecran 2 — **Hall of Legends** | `https://anotherworldcluj.ro/display/hall-of-legends` | clasamentul de fidelitate: jucătorii cu cele mai multe aventuri finalizate |

Între conținutul principal rulează automat materiale promoționale, prezentări de jocuri, logo animat și ceasul.

---

## 1. Prima pornire a unui PC (asociere)

1. În CRM: **Ecrane afișare → Ecrane → (rândul ecranului) → Mai mult → Asociază dispozitiv**. Apare un cod de tipul `K7MQ-4XPA`, valabil **30 de minute**, de **unică folosință**.
2. Pe PC-ul monitorului deschide adresa ecranului (tabelul de mai sus) și introdu codul → **Activează**.
3. PC-ul primește o credențială proprie, păstrată doar în browserul lui. Adresa nu conține secrete, PC-ul nu are cont de administrator și vede doar datele publice ale acestui ecran.

**Revocare**: Ecrane → Mai mult → **Revocă accesul dispozitivului**. La următoarea actualizare (câteva secunde) ecranul nu mai primește date și cere un cod nou. O nouă asociere invalidează automat dispozitivul vechi.

**Stare în CRM**: „Online” = playerul a răspuns în ultimele 90 de secunde. Browserul nu poate confirma dacă panoul fizic al monitorului este aprins — „Online” înseamnă doar că PC-ul și pagina rulează.

## 2. Browser în mod kiosk, pornire automată, fără sleep (Windows)

Aplicația nu poate modifica setările sistemului de operare; pașii de mai jos se fac o singură dată pe fiecare PC.

**Scurtătură kiosk (Google Chrome sau Microsoft Edge)** — Desktop → clic dreapta → Nou → Comandă rapidă, cu ținta:

```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --noerrdialogs --disable-session-crashed-bubble --autoplay-policy=no-user-gesture-required --user-data-dir="C:\AW-Display\chrome" https://anotherworldcluj.ro/display/arena-live
```

Pentru Edge: `"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" --kiosk https://anotherworldcluj.ro/display/hall-of-legends --edge-kiosk-type=fullscreen --no-first-run`.

- Folosiți **un folder de profil dedicat** (`--user-data-dir`) — acolo stă credențiala ecranului; nu-l ștergeți.
- Ieșire din kiosk: `Alt + F4`. Fără kiosk, ecran complet cu **F11** (browserul nu poate intra singur în fullscreen fără o apăsare).

**Pornire la pornirea PC-ului**: `Win + R` → `shell:startup` → copiați acolo scurtătura. Activați autentificarea automată a utilizatorului Windows (sau un cont local dedicat), ca browserul să pornească fără tastatură după o pană de curent.

**Fără sleep / screensaver**: Setări → Sistem → Alimentare → „Ecran: niciodată”, „Repaus: niciodată”; Personalizare → Ecran de blocare → Screen saver: „(Niciunul)”. În BIOS, dacă există, „Restore on AC power loss: Power On”, ca PC-ul să repornească singur după o pană de curent.

**Actualizări Windows**: setați „orele active” în afara programului arenei, ca repornirile să nu cadă în timpul zilei.

## 3. Cum funcționează actualizarea

- Playerul cere starea la ~8 secunde; dacă nu s-a schimbat nimic, serverul răspunde scurt (304), fără să retrimită datele. Datele noi se aplică pe loc: fără reîncărcarea paginii, fără să repornească video-ul curent.
- Ora vine de la server și se afișează pe fusul Europe/Bucharest (inclusiv trecerea la ora de vară/iarnă).
- **Fără internet**: rămâne ultima stare, cu indicatorul discret „Reconectare…”; „LIVE” devine „RECONECTARE” (datele vechi nu se prezintă drept live). După pragul setat (implicit 2 minute) ecranul trece pe conținut promoțional local (imagini păstrate în cache, logo, ceas, jocuri), fără date despre jucători. Revine singur la reconectare.
- Imaginile aprobate se păstrează local (maximum 220 de fișiere; cele care nu mai sunt folosite se șterg automat). Datele despre jucători nu se salvează pe PC.
- Un material defect (imagine lipsă, video care nu pornește în 7 secunde) se sare și se evită 10 minute.
- Playerul se reîncarcă singur o dată pe noapte (04:30–04:40), pentru funcționare îndelungată fără acumulare de memorie. Din CRM: **Mai mult → Reîncarcă playerul**.

## 4. Jucători și nickname-uri (recepție)

- **Clientul / organizatorul** (cine rezervă) nu este automat jucător. Fiecare jucător are profilul lui: **CRM → Jucători (nickname și avatar)**.
- Nickname-ul se completează:
  - de jucător, în **declarația de participare online** (opțional; apare „De verificat” până îl confirmă recepția);
  - la recepție, în **Jucători → Jucător nou** sau direct în sesiune (**Jucător nou** / **Completează nickname**).
- Reguli: 3–18 caractere (litere cu diacritice, cifre, spațiu, punct, cratimă, underscore), fără HTML sau emoji, unic (fără să conteze literele mari, diacriticele și separatorii), fără cuvinte nepotrivite. **Respinge nickname** îl blochează pentru toată lumea și lasă profilul „de completat”.
- Schimbarea nickname-ului **nu creează alt jucător**: istoricul rămâne pe același profil.
- Profilurile vechi (clienții existenți) nu au fost transformate în jucători și nu s-au generat nickname-uri din nume.

**Afișarea pe ecrane** este o preferință separată (nu este acord de marketing și nici acord foto): în declarația online („Sunt de acord ca nickname-ul și avatarul meu să apară pe ecrane…”; pentru minori bifează părintele) sau la recepție (**Afișare pe ecrane**, cu numele adultului pentru minori). Fără acord, jucătorul apare în sesiune ca **„Explorator 03”** cu avatar neutru și nu apare în Hall of Legends; istoricul lui rămâne corect în CRM. Retragerea se vede pe ecrane la următoarea actualizare.

Avatarul se atribuie automat și rămâne același la fiecare vizită; se poate schimba din profil sau din sesiune. Colecția (36 de avatare: exploratori, roboți, astronauți, fantasy, creaturi, măști) este originală; fișierele sunt în `public/media/avatars` și se regenerează cu `node tools/avatars/generate.cjs`.

## 5. Sesiunea de joc (operator / recepție)

Ecranul Live arată **doar sesiunea pornită de operator** — ora din rezervare nu înseamnă că jocul a început.

1. **Recepție → Check-in → Participanți și sesiune de joc** (sau din fișa rezervării: **Sesiune de joc**). Se deschide sesiunea creată din rezervare (arena, jocul, durata pachetului). Fără rezervare: **Recepție → Sesiuni de joc → Sesiune nouă**.
2. **Participanți**:
   - **Adaugă din declarații** — toți cei care au semnat declarația pentru rezervare;
   - **Adaugă jucător existent** — căutare după nickname (sugestii: jucătorii clientului și cei din declarații);
   - **Jucător nou** — nickname, avatar, acord de afișare.
   Organizatorul nu trebuie să știe nickname-urile la rezervare.
3. Verifică lista: cei cu **„NICKNAME DE COMPLETAT”** primesc nickname (**Completează nickname**); cei „de verificat” se aprobă (**Aprobă nickname-urile de verificat**).
4. **Pornește sesiunea** → ecranul Live prezintă „O nouă aventură începe” și echipa.
5. În timpul jocului: **Pauză / Reia**, **Durată** (ajustare), **Mai mult → Anulează sesiunea** (nu se numără).
6. **Finalizează** → „Misiune încheiată” pe ecran; participanții activi primesc câte **o** aventură în Hall of Legends (finalizarea nu se poate număra de două ori). Cei rămași doar „înregistrați” devin „neprezentare”.
7. **Rezultate** (doar dacă jocul are o regulă confirmată: scor mai mare, timp mai mic sau echipe — se alege în fișa sesiunii/jocului). Pozițiile se calculează automat; egalitate = aceeași poziție. Pe ecran apare „Rezultate introduse de echipa arenei”. **Proiectul nu are o sursă automată de scoruri**: nu se afișează niciodată un clasament inventat; interfața `ResultsProvider` este pregătită pentru o integrare viitoare (rezultatele sincronizate apar marcate distinct).
8. **Corecții** (după finalizare): pe participant → **Mai mult** → Nu s-a prezentat / Scoate din sesiune / Exclude din clasament / Repune — cu motiv obligatoriu; totul intră în jurnalul de audit.

Pe aceeași arenă poate rula o singură sesiune la un moment dat; fiecare ecran Live este asociat unei arene (Ecrane → Setări → Arena), deci participanții din arene diferite nu se amestecă.

## 6. Cum se calculează Hall of Legends

- **O participare activă la o sesiune finalizată = o aventură** pentru jucător. Perechea sesiune–jucător este unică, deci nu există credit dublu.
- Nu se numără: sesiunile **anulate** sau **de test**, **neprezentările**, participanții **scoși** și participările **excluse** printr-o corecție. Numărul de rezervări ale organizatorului nu contează.
- Apar doar jucătorii cu nickname aprobat și acord de afișare.
- Egalitate → aceeași poziție (ex. 1, 1, 3); ordinea secundară este alfabetică după nickname (stabilă, fără rearanjări).
- Implicit: clasament general, top 10 pe ecran, cu rotație automată pentru pozițiile următoare. Din **Ecrane → Setări**: luna curentă, săptămâna curentă sau un interval; perioada apare pe ecran.
- Nivelurile de fidelitate (badge-uri) se setează în **Setări → Ecrane afișare** (ex. „1 = Recrut, 5 = Aventurier, 10 = Veteran, 25 = Legendă”).
- Nu există import istoric: participările vechi, care nu pot fi legate de jucători, nu se inventează.

## 7. Reclame, playlisturi, comenzi rapide

- **Ecrane afișare → Biblioteca promoțională → Material nou**: alegi din biblioteca media sau încarci (JPEG/PNG/WebP/AVIF sau MP4/WebM, cu drepturi de utilizare cunoscute); text opțional, încadrare (umple / întreg pe fundal estompat — materialele verticale se afișează automat întregi), pondere, durată, ecranele și perioada.
- **Ecrane afișare → Playlisturi**: secvențele se reordonează prin tragere; mod „aleatoriu controlat” (fără același material de două ori la rând, cu ponderi, durată minimă a conținutului principal și limită de reclame la rând) sau „secvențial”; fiecare secvență poate avea perioadă, interval orar și zile. Pe pagina playlistului există **previzualizarea rotației**.
- Pe Live, în timpul jocului: reclamele sunt scurtate (implicit max. 8 s), lipsesc în ultimele minute (implicit 5) și pot fi oprite complet cu **Live fără întreruperi** (Ecrane → Setări).
- **Comandă rapidă** (Ecrane → rândul ecranului): Afișează acum sesiunea live / clasamentul / materialul selectat / Pauză reclame, pentru 5 minute – 3 ore. Comanda expiră singură; **Revino la automat** o oprește imediat.
- Setările fiecărui ecran (titluri, poziții, perioadă, durata paginilor, ora/data, intensitatea animațiilor, volum — implicit mut, reguli de reclamă, text și destinație QR, prag offline) au **Revino la setările implicite**.

## 8. Confidențialitate

- Pe ecrane: doar nickname aprobat și avatar. Niciodată nume, telefon, e-mail sau date de rezervare.
- Numele real al jucătorului (opțional, intern) și numele adultului care a dat acordul pentru un minor se păstrează criptat.
- Profilurile fără nicio sesiune în perioada din **Setări → Retenție** (implicit 36 de luni) se anonimizează automat; participările rămân doar ca statistică.
- **De completat de operator**: politica de confidențialitate trebuie să menționeze afișarea nickname-ului pe ecranele locației (temei: consimțământ, retragere oricând la recepție) și perioada de păstrare a profilurilor.
