# Analiza fotografiilor de referință Evolvo

Scop: înțelegerea fluxurilor existente (calendar, orare, concedii, programări, rapoarte) pentru a construi funcționalități proprii, independente de Evolvo.

Reguli aplicate:
- Fotografiile conțin date personale (pacienți, telefoane, angajați). Ele **nu** sunt copiate în repository și datele din ele **nu** sunt folosite în demonstrații sau seed-uri.
- Fotografiile nu documentează întregul sistem; observațiile de mai jos sunt doar ce se vede efectiv.

## Tura 1 (5 fotografii)

### Navigație generală
- Meniu principal: **Pacienți · Marketing · Instituție · Setări · Rapoarte · Notificări**.
- În colțul din dreapta sus există selectoare de context: **medic** și **punct de lucru** (locație). Calendarul și programul de lucru se raportează la combinația medic + locație.

### Setări → Program de lucru
Sub-taburi Setări: Servicii medicale, Materiale, Lista de preț, Setări utilizator, Program de lucru, Texte predefinite, Șabloane, Tipuri pachete servicii, Liste medicale.

**Program de lucru** (șablon de orar):
- Denumire program (ex. numele medicului).
- Valabil în perioada (dată început – dată sfârșit; se văd intervale de ~10 ani).
- **Alternează**: săptămânal (probabil și lunar), **ciclul de alternare** (număr de cicluri), **începe cu ciclul nr.** → suportă orare care se repetă pe săptămâni alternante (ex. săptămâna 1 / săptămâna 2).

**Orarul zilnic** (tabel pe zile Luni–Duminică × coloană „Săpt./Luna” = indexul ciclului):
- Fiecare zi are până la 6 segmente: **Dimineața, Înainte de masă, După masă, Seara, Suplimentar 1, Suplimentar 2**.
- Fiecare segment este un interval orar (ex. 09:00–14:00).

**Editare segment** (dialog „Modificare”), pentru fiecare segment:
- Interval orar ales cu slider 00:00–23:59.
- Denumire.
- **Tipul serviciului** (restricționează ce servicii se pot programa în segment).
- **Resurse** (cabinet/aparatură).
- **Durată** slot (pas de 5 minute).
- **Culoare** (afișare în calendar).
- **Online: Da/Nu** → controlează dacă segmentul poate fi rezervat de pe site.

### Setări → Zile nelucrătoare
Coloane: **Vacanța pentru** (un angajat sau „toate”), **Punct de lucru** (o locație sau „toate”), **Valabil în perioada**, **Descriere** (ex. concediu, „szabadság”).
→ Excepțiile pot fi la nivel de persoană, de locație, sau globale. Descrierile sunt introduse liber, în română sau maghiară.

Puncte de lucru vizibile (de confirmat cu site-ul public și caietul de sarcini):
- Tg. Mureș, Str. Poștei Nr. 3
- Tg. Mureș, Piața Trandafirilor 53
- Tg. Mureș, Str. Gheorghe Doja 64-68
- Tg. Mureș, Piața Republicii 5
- Sovata, Str. Principală 196

Personalul include atât **medici** („Dr. …”), cât și **optometriști** (marcați explicit în nume), ceea ce confirmă necesitatea unui tip de specialist distinct.

### Pacienți → fișa pacientului → Programări (calendar)
Taburi fișă pacient: **Date personale, Programări, Fișe medicale, Servicii, Financiar, Imagistică, Documente, Pachete servicii**.

Calendar:
- Navigator temporal pe ani / luni / zile, cu ziua curentă evidențiată.
- Vizualizare săptămânală (Luni–Duminică), rânduri de 15 minute.
- Card programare: avatar, interval orar, nume pacient, serviciu (ex. Consultație, Control, Lentile de contact), culoare după serviciu/segment.
- Popover programare: medic, pacient, cod pacient, telefon, serviciu + interval, **status** (ex. „Programat”), descriere stare, **„Dezactivare notificări”** per programare, jurnal (înregistrat de / modificat de / ultima notificare, cu dată și oră), butoane rapide de acțiune (confirmare, anulare, mesaje etc.).
- Butoane pentru programare nouă și o listă (posibil listă de așteptare).

### Rapoarte
Sub-taburi: Registrul de pacienți, Programări, Raport servicii medicale, Raport fișe medicale, Statistici, Raport încasări, Raport avansuri, Datorii pacienți, Raport facturi, Raport salarizare, Cheltuieli parteneri, Facturi parteneri, un raport suplimentar (denumire ilizibilă), Solicitări către laborator, Pachete servicii.

**Registrul de pacienți** – taburi: Pacienți înregistrați, Vizite pacienți, **Pacienți duplicați**, Temporizator, Pacienți noi.
- Grilă cu filtre pe coloane: Nume, Cod pacient, Data nașterii, Email, CNP, Nr. telefon, Adresă, medic, Înregistrat de, date de înregistrare / ultima vizită; paginare.
- Grafic „Evoluția numărului pacienților noi înregistrați” – bare zilnice, weekendurile marcate, cu navigator pentru selectarea perioadei.

### Implicații pentru platforma nouă
1. Modelul de orar trebuie să suporte: perioade de valabilitate, cicluri alternante, mai multe segmente pe zi, fiecare cu servicii permise, resursă, durată slot, culoare și flag „online”.
2. Excepțiile (concedii) se aplică pe persoană, locație sau global, și au prioritate față de orar.
3. Disponibilitatea online = segmente cu flag online − programări existente − excepții − suprapuneri ale aceluiași medic în alte locații.
4. Programarea are status, jurnal de audit și opțiune de oprire a notificărilor per programare.
5. Detectarea pacienților duplicați este o funcție explicită (relevant și pentru import).
6. Rapoartele includ zona financiară (încasări, avansuri, datorii, facturi), laborator și pachete de servicii; acoperirea exactă va fi stabilită din caietul de sarcini.

## Tura 2 (5 fotografii)

### Calendar – stare goală și controale
- Aceeași vizualizare săptămânală; ziua curentă are fundal evidențiat, iar un marcaj roșu pe axa orelor indică ora curentă.
- Controale în dreapta sus: pictograme de filtrare/vizualizare, **„Lista de așteptare (n)”**, buton „Actual” (salt la azi) și buton „Programări”.
- Calendarul este gol pentru combinația medic + locație selectată → contextul (medic, locație) filtrează complet grila.

### Instituție
Sub-taburi: Date instituție, Parteneri, Gestionare utilizatori, Setări instituție, Setări financiare, Setări salarizare, Salvarea bazei de date, Integrare API, **Programare online**, Setări fișe medicale, Pontajul asistenților.

### Instituție → Programare online
- Text explicativ: se poate adăuga un **cod scurt pe site** prin care pacienții solicită programări direct (widget extern; în platforma nouă programarea va fi nativă în site).
- Secțiuni: Instrucțiuni, Setări programare online, Setări aplicație.
- Tabel pe **puncte de lucru**: activ/inactiv online, adresă, **alias public** („Optica Optofarm <localitate>, <adresă>”), cu listă expandabilă de utilizatori (medici) per locație.

Puncte de lucru configurate (8; de confirmat cu site-ul și caietul de sarcini):

| Punct de lucru | Online |
|---|---|
| Tg. Mureș, Bd. 1 Decembrie 1918 nr. 49 | inactiv |
| Tg. Mureș, Str. Poștei nr. 3 | activ |
| Tg. Mureș, Piața Trandafirilor 53 | activ |
| Tg. Mureș, Str. Gheorghe Doja 64-68 | activ |
| Sovata, Str. Principală 196 | activ |
| Tg. Mureș, Piața Republicii 5 | activ |
| Reghin, Str. Școlii 11 | activ |
| Tg. Mureș, Fortuna (Bd. 1 Decembrie 1918 nr. 182-184) | activ |

**Setări per medic × locație pentru programare online** (dialog „Modificare”):
- **Alias** public (cu prefix numeric `#0`, `#2`, `#3`… – pare a fi ordine de afișare sau grupare medici/optometriști).
- **Informații medic** în **RO, EN și HU** (text public).
- **Mesaj de pauză programare online** (afișat când programarea online e suspendată pentru acel medic).
- **Descriere** – lista serviciilor oferite (ex. prescriere ochelari, fund de ochi, retinofotografie).
- **Programabil după**: număr minim de zile de la data curentă (0–180).
- **Durată implicită** a programării online (5–150 min, implicit 15) sau opțiunea **„Utilizează durata setată în programul de lucru”**.
- **Programări paralele**: număr de pacienți acceptați simultan în același slot (implicit 1).
- **Primește email** / **Primește notificare** la programare online.
- În lista per locație, fiecare medic are bifă de activare online, alias, mesaj de pauză și descriere → aceeași persoană poate fi activă online într-o locație și inactivă în alta.

### Instituție → Gestionare utilizatori
- Listă cu: acțiuni rapide (parolă, blocare/deblocare, drepturi, documente, autentificare ca utilizator, profil, fotografie), nume, utilizator, **status** (Activat), **rol** (Medic / Asistent / Admin), tip (General), email, telefon, dată creare.
- Fotografia medicului este gestionată din contul de utilizator (relevant pentru afișarea pe site).
- Aproximativ 30+ conturi, majoritatea medici și asistenți.

### Drepturile modulelor (per utilizator)
- Ecran „Drepturile modulelor pentru <utilizator>”, cu preset „Drepturi personalizate”.
- Pentru fiecare modul, un nivel: **„Toate drepturile”** sau **„Fără drepturi”** (posibil și niveluri intermediare, nevizibile în fotografie).
- Module vizibile:
  - Pacienți – Date personale, Programări, Fișe medicale, Servicii, Financiar, Imagistică, Documente, Pachete servicii
  - Marketing – Email/SMS, CRM
  - Instituție – Date instituție, Parteneri, Gestionare utilizatori, Setări instituție, Setări financiare, Setări salarizare, Salvarea bazei de date, Setări fișe medicale, Programare online, Integrare API, Pontajul asistenților
  - Setări – Servicii medicale, Materiale, Lista de preț, Setări utilizator, Program de lucru, Texte predefinite, Șabloane, Tipuri pachete servicii, Liste medicale
  - (lista continuă sub marginea ecranului, probabil Rapoarte și Notificări)
- Observație: drepturile sunt pe modul, aproape binare, și **nu** sunt legate de locații. Cerința noii platforme (permisiuni granulare, pe acțiune și pe locațiile atribuite, verificate pe server) este mai strictă.

### Implicații suplimentare
7. Setările de programare online sunt la nivel **medic × locație**: activare, alias, texte publice RO/HU, mesaj de pauză, termen minim de programare, durată, capacitate paralelă, destinatari notificări.
8. Capacitatea paralelă (>1 pacient pe slot) trebuie tratată explicit în algoritmul anti-suprapunere: suprapunerile sunt permise doar în limita capacității configurate și niciodată între locații diferite pentru același medic.
9. Lista de așteptare este o funcție existentă, folosită la recepție.
10. Modelul de permisiuni trebuie să acopere cel puțin modulele de mai sus, dar cu granularitate pe acțiune (vizualizare/creare/modificare/ștergere/export) și pe locație.
11. Profilurile publice ale medicilor (fotografie, descriere, servicii) trebuie să provină din aceeași sursă cu setările de programare, pentru ca site-ul și CRM-ul să nu se desincronizeze.
