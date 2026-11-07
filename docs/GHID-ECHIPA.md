# Ghid pentru echipă — panoul de administrare

Adresa: `https://anotherworldcluj.ro/admin`. Fiecare persoană are contul ei; nu se folosesc conturi comune.
Ce vedeți în meniu depinde de rol. Rolurile manager, contabil, marketing și super admin cer la autentificare și codul din
aplicația de autentificare (2FA).

---

## Recepție

### Începutul zilei
* **Tablou de bord**: rezervările de azi, sosirile următoare, cererile noi.
* **Recepție → Calendar** (zi / săptămână / lună): ocuparea arenei. Un interval se poate bloca (mentenanță, eveniment intern),
  o dată sau recurent.

### Rezervare la telefon sau la ghișeu
**Rezervări → Rezervare nouă**: pachet, dată, oră din lista orelor libere, participanți pe categorii de vârstă, datele
adultului responsabil. Dacă clientul are un cod de reducere, completați-l la **Detalii → Cod de reducere**: totalul
estimat arată imediat reducerea sau motivul pentru care codul nu se aplică. Disponibilitatea se verifică în momentul salvării; dacă între timp ora a fost ocupată online,
rezervarea este refuzată și alegeți altă oră.

### Sosirea grupului
**Recepție → Check-in**: scanați codul QR din e-mailul/pagina clientului (camera tabletei) sau introduceți codul rezervării.
Lista „Sosiri azi” permite check-in direct. Check-in-ul este disponibil cu cel mult 2 ore înainte de început.

### Declarații de participare (acceptarea riscurilor)
Fiecare jucător (sau părintele, pentru un minor) semnează declarația **înainte de joc**, pe telefon:
* **Din e-mail / pagina rezervării**: clientul primește linkul „Completează declarația” în confirmare și în reminder și îl
  poate trimite celorlalți participanți. Pagina arată câte declarații s-au completat din numărul de participanți.
* **La ghișeu**: **Recepție → Declarații de participare → Cod QR pentru recepție** tipărește afișul cu codurile QR (RO și EN).
  Jucătorul scanează, introduce opțional codul rezervării și semnează pe ecran.
* Pentru cine are sub 18 ani în ziua jocului, formularul cere automat datele părintelui/tutorelui, care semnează el.
* La **Check-in** apare „Declarații X/Y”. Deschideți lista, verificați numele (și actul, dacă e cazul) și apăsați
  **Validează** — declarația devine „Validată”, cu numele dvs. și ora.
* Declarația se poate tipări (buton **Tipărește**). Textul semnat rămâne cel al versiunii de atunci, chiar dacă documentul se
  schimbă ulterior. Data nașterii, telefonul, e-mailul și semnătura sunt criptate; declarațiile se șterg automat după
  perioada din **Setări → Retenție** (implicit 36 de luni).
* Rolul „operator” vede doar numărul de declarații la check-in, nu și datele lor.

### Încasare
Pe pagina rezervării: **Încasează** → metoda (numerar, card POS, transfer) și suma (propusă automat: avansul sau restul).
* „Emite bon fiscal” apare pentru numerar și card, dacă o casă de marcat este configurată. Dacă scrie
  **SIMULARE**, casa de marcat nu este conectată: bonul real se emite direct din aparat.
* Un dublu-clic nu înregistrează încasarea de două ori.
* Lista încasărilor rezervării este în partea de jos a paginii.

### Modificări
Pe pagina rezervării: **Confirmă**, **Reprogramează** (doar ore libere), **Anulează** (cu motiv), **Neprezentare**,
**Finalizează**, **Modifică detaliile**, **Retrimite e-mailul**. Clientul primește automat mesajele corespunzătoare
(când integrarea de e-mail este activă), iar reminderele se recalculează la reprogramare.

### Rambursare
Pe încasare: **Rambursare** → sumă și motiv. Cererea ajunge la manager pentru aprobare (Financiar → Rambursări).
Plățile online se returnează automat pe card; cele în numerar se returnează manual, iar aplicația doar le consemnează.

### Vouchere
* Voucherele cumpărate online apar automat în **Fidelizare → Vouchere cadou** (origine „vânzare”), cu cod, pachet,
  număr de persoane și valabilitate de 6 luni; echipa primește un e-mail la fiecare vânzare (adresa pentru alerte interne).
**Fidelizare → Vouchere cadou**: emitere, tipărire (format A5 cu cod QR), utilizare la plată, anulare.

---

## Manager

* **Financiar → Rambursări**: cererile de aprobat (numărul apare lângă meniu). *Aprobă* / *Respinge*. O rambursare
  „În procesare” (răspuns neclar de la procesator) se poate reîncerca fără risc de dublă returnare.
* **CRM → Cereri evenimente** (aniversări, companii): fluxul pe etape, oferte, conversia în rezervare.
* **CRM → Clienți**: istoric, note interne, consimțăminte, unirea duplicatelor, exportul și anonimizarea datelor la cerere.
* **Catalog**: jocuri, pachete (prețuri, avans, capacitate), servicii suplimentare.
* **Configurare**: program, zile speciale, resurse ale arenei, setări (date firmă, reguli rezervare, retenție).
* **Sistem → Utilizatori**: conturi noi, dezactivare la plecarea unui angajat, resetarea 2FA.
* **Tablou de bord → Pregătire lansare**: ce mai lipsește (date de completat, integrări, documente, serie de facturare,
  casa de marcat).

## Contabil

* **Financiar → Facturi**: ciorne (manual sau din rezervare: *Mai multe → Factură*, de avans sau finală, cu regularizarea
  avansurilor), **Emite factura** (numărul se alocă din serie, documentul se blochează), PDF, **Stornează**.
* **e-Factura**: *Generează și transmite* — XML-ul se validează local, apoi se trimite la ANAF; starea se actualizează automat.
  Termenul legal apare pe fiecare factură. O transmitere cu „rezultat incert” se verifică în SPV înainte de retrimitere.
* **Financiar → Bonuri fiscale**: bonurile „Incert — de verificat” se reconciliază din jurnalul electronic al aparatului.
* **Financiar → Export contabilitate**: lunar — facturi, încasări și rambursări, facturi de la furnizori (CSV pentru Excel)
  și arhiva cu PDF-urile facturilor și XML-urile e-Factura.
* **Stocuri**: facturi de la furnizori (manual sau preluate din SPV), recepții (NIR) cu tipărire, stoc curent, consum
  și casări, inventar.

### Recepția mărfii (NIR)
1. **Stocuri → Facturi furnizori → Înregistrează factură** (sau, din **Facturi primite (SPV)**, *Înregistrează*).
2. Pe fiecare linie de marfă alegeți articolul din stoc (o singură dată per articol nou: **Stocuri → Articole**).
3. Pe factură: **Recepție (NIR)** → gestiunea → corectați cantitățile primite efectiv → **Confirmă recepția** → **Tipărește NIR**.

### Inventar
**Stocuri → Inventare → Inventar nou** → completați coloana „Numărat” (se salvează la fiecare câmp) → **Confirmă inventarul**.
Diferențele devin mișcări de stoc „Inventar”.

## Editor (conținut site)

* **Conținut site → Pagini**: editare pe blocuri, previzualizare, salvare ca ciornă, publicare imediată sau programată,
  istoric cu restaurare. O limbă se publică doar dacă are titlu, adresă și conținut complete.
* **Media**: încărcare imagini (se generează automat variante optimizate; metadatele de locație se elimină).
  Pentru fiecare imagine completați **sursa și drepturile** și dacă apar minori (cu acordul părintelui).
  Imaginile din jocuri nu se folosesc ca fotografii ale locației.
* **Întrebări frecvente, Noutăți, Bannere, Galerie, Testimoniale, Meniuri, Redirecturi.**
* **Documente juridice**: se editează doar ciornele; publicarea creează o versiune nouă (versiunile vechi rămân în istoric).

## Marketing

* **Marketing → Segmente**: criteriile includ automat doar clienții cu consimțământ activ pe canalul respectiv.
* **Campanii**: e-mail sau SMS; costul SMS estimat înainte de trimitere; **trimiterea de test** (către adresa proprie) este
  obligatorie înainte de programare; ore de liniște și limită de frecvență respectate automat.
* **Importuri contacte**: doar liste cu dovada consimțământului (sursă, dată); rândurile fără consimțământ explicit se importă
  fără permisiune de marketing.
* **Fidelizare**: niveluri, recompense, puncte (acordate automat la rezervările finalizate și achitate).
* **Deschiderea oficială**: data se setează în **Setări locație → Data deschiderii oficiale**. Până atunci, prima pagină afișează
  numărătoarea inversă (blocul „Deschidere oficială”), iar rezervările online încep din ziua deschiderii; recepția poate
  rezerva oricând. Blocul dispare singur după ziua deschiderii.
* **Coduri de reducere** (**Marketing → Coduri de reducere**): tip (persoane gratuite, procent sau sumă fixă), pachetele la
  care se aplică, perioada în care se poate folosi, perioada sesiunilor și câte folosiri are fiecare client (recunoscut după
  e-mail sau telefon). Marketingul vede codurile; doar cine are dreptul de a modifica prețuri le creează sau le schimbă.
  Codul se verifică la rezervare (pe site și la recepție), reducerea apare separat pe rezervare și pe factură, iar o
  rezervare anulată eliberează codul. Un cod folosit nu se șterge: se dezactivează. Blocul „Cod de reducere” de pe prima
  pagină și bannerul dispar singure când codul expiră.
* **WELCOME13** (oferta de deschidere): o persoană gratuită la „Sesiune VR — 60 de minute”, pentru sesiunile din 13–15
  noiembrie, o singură dată per client, se poate folosi până pe 15 noiembrie.

## Social media

* **Social media → Planul săptămânii**: pentru fiecare zi, postarea planificată cu imaginile gata de descărcat
  (Instagram 4:5, pătrat 1:1, story 9:16 sau original) și textul complet, cu hashtag-uri.
* Fluxul zilnic: **Descarcă tot** (imaginile + `text.txt`) sau descărcați imaginea în formatul dorit → **Copiază textul** →
  publicați din aplicația Instagram / Facebook / Meta Business Suite → **Am publicat**. Aplicația nu publică singură pe rețele.
* **Propune postări pentru zilele libere**: completează zilele fără postare din săptămâna afișată, cu o temă pe zi
  (jocul săptămânii, prieteni, întrebarea săptămânii, aniversări/familie, weekend, companii, din culise), imagini nefolosite
  în ultimele 28 de zile și texte construite doar din informațiile din panou. Verificați și ajustați textul înainte de publicare.
* **Toate postările**: listă, filtre, editare (data, ora, canale, text, imagini în ordinea din carusel), note interne.
* Imaginile cu minori apar doar dacă acordul este confirmat în **Media**; fotografiile din pachetul francizei sunt din alte
  arene ale rețelei, deci textele nu spun că sunt din Cluj. După deschidere, înlocuiți-le treptat cu fotografii proprii
  (cu acordul persoanelor din poze).
* Planul pentru 2–29 noiembrie 2026 (campania de deschidere, cu grafice de numărătoare inversă) este deja încărcat.

## Asistent AI

* **Asistent AI → Bază de cunoștințe**: răspunsurile asistentului se bazează doar pe intrările **aprobate**. Orice modificare
  readuce intrarea în ciornă până la o nouă aprobare.
* **Conversații**: istoric (datele de contact sunt mascate), costul pe conversație și pe zi.

---

## Bune practici

* Nu notați date medicale sau CNP în note sau în mesaje.
* Nu trimiteți parole sau chei pe e-mail/chat; integrările se configurează doar din panou.
* Deconectați-vă pe calculatoarele comune; la pierderea telefonului cu aplicația 2FA, cereți resetarea unui administrator.
