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
adultului responsabil. Disponibilitatea se verifică în momentul salvării; dacă între timp ora a fost ocupată online,
rezervarea este refuzată și alegeți altă oră.

### Sosirea grupului
**Recepție → Check-in**: scanați codul QR din e-mailul/pagina clientului (camera tabletei) sau introduceți codul rezervării.
Lista „Sosiri azi” permite check-in direct. Check-in-ul este disponibil cu cel mult 2 ore înainte de început.

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

## Asistent AI

* **Asistent AI → Bază de cunoștințe**: răspunsurile asistentului se bazează doar pe intrările **aprobate**. Orice modificare
  readuce intrarea în ciornă până la o nouă aprobare.
* **Conversații**: istoric (datele de contact sunt mascate), costul pe conversație și pe zi.

---

## Bune practici

* Nu notați date medicale sau CNP în note sau în mesaje.
* Nu trimiteți parole sau chei pe e-mail/chat; integrările se configurează doar din panou.
* Deconectați-vă pe calculatoarele comune; la pierderea telefonului cu aplicația 2FA, cereți resetarea unui administrator.
