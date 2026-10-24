# Securitate și protecția datelor

Ce măsuri sunt implementate, unde se află în cod și ce rămâne în responsabilitatea operatorului.
Verificările automate (114 teste) rulează la fiecare modificare în GitHub Actions, împreună cu auditul dependențelor
(`composer audit`, `npm audit`). La ultima rulare nu existau vulnerabilități cunoscute în dependențe.

## 1. Acces în panou

| Măsură | Detalii | Cod |
|---|---|---|
| Roluri și permisiuni | super admin, manager, recepție, operator, marketing, contabil, editor; fiecare ecran și acțiune verifică permisiunea | `app/Modules/Core/Access/Permissions.php`, `app/Filament/Concerns/ChecksPermissions.php` |
| Autentificare în doi pași | obligatorie pentru super admin, manager, contabil, marketing (aplicație TOTP sau cod pe e-mail, coduri de recuperare) | `RequireMfaForRole`, `AdminPanelProvider` |
| Parole | minim 12 caractere, litere mari și mici, cifre; în producție, verificare față de listele de parole compromise (k-anonymity) | `AppServiceProvider` |
| Limitarea încercărilor de autentificare | limita panoului (5 încercări/minut) + jurnal pentru eșecuri și blocări | `AuthenticationAuditSubscriber` |
| Conturi | fără afișarea parolelor; resetare prin link; resetare 2FA și deconectare de pe toate dispozitivele de către administrator; conturile inactive nu intră în panou | `UserResource` |
| Jurnal de audit | autentificări, modificări de setări și integrări, emiteri de facturi, rambursări, exporturi, bonuri, stocuri, unirea și anonimizarea clienților | `AuditLogger`, ecranul Sistem → Jurnal de audit |

## 2. Aplicația web

| Risc | Măsură |
|---|---|
| CSRF | token pe toate formularele; excepții doar pentru webhook-uri (semnătură) și agentul fiscal (HMAC) |
| XSS | Blade escapează implicit; textele din CMS, e-mailuri și asistent sunt Markdown cu HTML brut eliminat și fără linkuri nesigure (`SafeMarkdown`); CSP strict cu *nonce* pentru scripturi pe site |
| SQL injection | Eloquent / query builder cu parametri; expresiile brute folosesc doar valori întregi calculate de aplicație |
| IDOR | linkurile de gestionare a rezervării sunt tokenuri aleatoare stocate ca hash; descărcările (facturi, NIR, XML) verifică permisiunea; contul clientului vede doar rezervările proprii |
| Clickjacking | `X-Frame-Options: SAMEORIGIN`, `frame-ancestors 'self'` |
| Antete | HSTS pe HTTPS, `nosniff`, `Referrer-Policy`, `Permissions-Policy` (camera permisă doar în panou, pentru scanarea QR la check-in) |
| Abuz formulare | limite pe IP (formulare 6/minut și 40/oră, asistent AI, API rezervări, webhook-uri, agent fiscal), câmp-capcană, opțional Cloudflare Turnstile |
| Fișiere încărcate | doar imagini, limită 40 MB / 40 MP, reprocesate (variante noi, metadate EXIF eliminate); importurile CSV pe disc privat |
| Export CSV | valorile care încep cu `= + - @` sunt neutralizate (fără formule executate în Excel) |
| Sesiuni | criptate, cookie `Secure` + `HttpOnly` + `SameSite=Lax` (setări în `.env.example`) |
| HTTPS | forțat în producție (`URL::forceScheme`) |

## 3. Plăți, facturare, fiscal

* **Nu se stochează date de card.** Plata se face pe pagina Stripe Checkout; aplicația primește doar identificatori și stări.
* Webhook-urile Stripe sunt acceptate doar cu semnătură validă și recentă (5 minute); fiecare eveniment se procesează o singură
  dată; suma și moneda trebuie să corespundă exact plății create de aplicație.
* Rambursările cer aprobarea unei persoane cu permisiunea „Aprobă rambursări”; reîncercările folosesc aceeași cheie de idempotență.
* Facturile emise nu mai pot fi modificate (protecție la nivel de model + amprentă a conținutului); corecțiile se fac prin storno.
  Numerotarea este continuă și verificată cu procese paralele reale.
* Agentul casei de marcat se autentifică prin HMAC (secretul nu circulă pe rețea); o comandă fără răspuns devine „incertă” și
  **nu** se retrimite automat (fără bonuri duble).

## 4. Secrete

* Cheile integrărilor sunt criptate cu `APP_KEY` în baza de date și nu sunt trimise niciodată înapoi în browser.
* `APP_KEY` trebuie păstrată în siguranță: fără ea, credențialele integrărilor și sesiunile nu pot fi decriptate.
  La rotirea cheii, vechea valoare se trece în `APP_PREVIOUS_KEYS`.
* Copiile de siguranță sunt criptate AES-256 cu `AW_BACKUP_PASSWORD` (păstrată și în afara serverului).

## 5. Date personale (GDPR)

* **Nu se colectează CNP sau date medicale.** Formularele avertizează explicit; pentru grupuri se cer doar numere pe categorii
  de vârstă. Datele copiilor nu se colectează; rezervarea o face un adult responsabil.
* Consimțămintele de marketing sunt separate pe canal (e-mail, SMS), **nebifate** implicit, cu dovada (text, moment, sursă)
  și istoric. Mesajele de marketing se trimit doar celor cu consimțământ activ; dezabonarea are efect imediat.
* Clientul își poate descărca datele și poate cere ștergerea din contul său; în panou există export și anonimizare
  (documentele fiscale se păstrează conform legii).
* Retenție configurabilă (Setări → Retenție date), aplicată zilnic: jurnal mesaje 365 zile, cereri pierdute 365 zile,
  jurnal de audit 730 zile, anonimizarea clienților inactivi fără obligații legale după 36 luni (valori implicite).
* În dezvoltare și test, mesajele către clienți rămân în *sandbox* (`AW_MESSAGING_SANDBOX`); datele demonstrative sunt
  fictive (`example.test`) și nu se instalează în producție.
* Analytics și pixeli de marketing se încarcă **doar după** acordul pentru cookie-uri.

## 6. Asistentul AI

* Cheia API rămâne pe server; browserul comunică doar cu aplicația.
* Modelul nu are acces la baza de date: poate folosi doar unelte definite, cu parametri validați (disponibilitate, ofertă,
  pregătirea unei rezervări, căutarea propriei rezervări cu cod **și** contact corespunzător, cel mult 3 încercări greșite).
* Nu poate modifica prețuri, emite documente fiscale sau confirma singur rezervări: confirmarea o face vizitatorul,
  prin buton, iar rezervarea trece prin aceleași reguli ca site-ul.
* Conversația este legată de sesiunea vizitatorului; jurnalul este criptat și minimizat (datele de contact redactate în panou).
* Buget zilnic de cost; la depășire, asistentul trimite către formular.

## 7. Responsabilitatea operatorului

* actualizări regulate (aplicație și dependențe) — CI semnalează vulnerabilitățile cunoscute;
* păstrarea `APP_KEY`, `AW_BACKUP_PASSWORD` și a codurilor de recuperare 2FA în afara serverului;
* revocarea conturilor la plecarea unui angajat (Sistem → Utilizatori → dezactivare + deconectare de pe toate dispozitivele);
* o restaurare de probă pe trimestru (`docs/INSTALARE-CPANEL.md`, secțiunea 10);
* validarea juridică a documentelor și a textelor de consimțământ.

## 8. Limitări cunoscute

* Nu există WAF propriu; recomandăm Cloudflare (sau protecția furnizorului de găzduire) în fața site-ului.
* Pe găzduire partajată, izolarea depinde de furnizor (alte conturi pe același server).
* Testele de securitate sunt automate și de cod; nu s-a efectuat un test de penetrare extern.
