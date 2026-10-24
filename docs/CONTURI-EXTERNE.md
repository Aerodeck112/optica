# Conturi externe și date de furnizat

Ce trebuie obținut sau decis **în afara aplicației** pentru lansare. Starea fiecărei integrări se vede în panou,
**Sistem → Integrări**, cu una dintre stările: *Neconfigurat*, *Adaptor pregătit*, *Testat în sandbox*,
*Configurat*, *Implementat și verificat în producție*, *Blocat*.

Regulă generală: cheile și parolele se introduc **numai** în panou (sunt criptate și nu mai sunt afișate după salvare),
nu se trimit pe e-mail, chat sau în documente.

---

## 1. Date ale firmei și ale locației (Configurare → Setări)

Fără ele site-ul afișează „date în curs de completare” acolo unde ar apărea și facturarea este blocată.

| Ce | Unde apare | Cine furnizează |
|---|---|---|
| Denumire juridică, CUI (cu `RO` doar dacă plătitor de TVA), nr. Reg. Com., sediu social, localitatea sediului | subsol site, facturi, e-Factura, documente juridice | administratorul firmei |
| IBAN, bancă | facturi (plata prin transfer) | administratorul firmei |
| Regim TVA (plătitor / neplătitor, TVA la încasare) | calculul TVA pe facturi și bonuri | **contabil** |
| Adresa exactă a arenei (stradă, număr, etaj), coordonate, link Google Maps | pagina Contact, date structurate pentru Google | administrator |
| Telefon principal, e-mail general / rezervări / protecția datelor | site, e-mailuri, documente juridice | administrator |
| Regulile de anulare și reprogramare (ore înainte) | pagina rezervării, documentele juridice | administrator (validat juridic) |
| Link SAL (ANPC) | subsol site | standard ANPC |
| Programul de funcționare și zilele speciale | rezervare online | administrator |
| Jocurile **confirmate** pentru Cluj, cu vârstă minimă, nr. jucători, durată | catalogul public | administrator / francizor |
| Pachetele: preț, durată sesiune și durată de joc, capacitate, avans | rezervare instant | administrator |
| Fotografii **reale** ale locației (cu drepturi de utilizare) | site; imaginile din jocuri nu se folosesc drept fotografii ale locației | administrator / fotograf |

Valorile necunoscute rămân „De completat” în panou și **nu** sunt afișate pe site.

---

## 2. Găzduire și domeniu

* Cont cPanel cu PHP 8.3+, MariaDB/MySQL, cron la 1 minut, SSL (vezi `docs/INSTALARE-CPANEL.md`).
* Domeniul `anotherworldcluj.ro` îndreptat către găzduire.
* O căsuță de e-mail pentru mesajele către personal (ex. `no-reply@anotherworldcluj.ro`) — în `.env`.
* Înregistrări DNS pentru livrabilitatea e-mailului: **SPF**, **DKIM** (din cPanel → *Email Deliverability* sau din Brevo) și
  **DMARC** (ex. `v=DMARC1; p=none; rua=mailto:...` la început, apoi mai strict).

---

## 3. E-mail către clienți (Integrări → E-mail)

Două variante:

| Variantă | Ce trebuie | Observații |
|---|---|---|
| **SMTP** (cont din cPanel) | server, port, utilizator, parolă, adresa expeditor | simplu; fără rapoarte de livrare; limite de trimitere ale găzduirii (contează la campanii) |
| **Brevo** (recomandat pentru campanii) | cont Brevo, domeniu verificat (DKIM), cheie API, un secret ales de voi pentru webhook | rapoarte de livrare/respingere. Webhook: `https://anotherworldcluj.ro/api/webhooks/brevo?key=SECRETUL_ALES` |

Stare actuală: **adaptor pregătit**, verificat doar în testele automate (fără trimiteri reale).
Pași: introduceți datele → *Test conexiune* → trimiteți un mesaj de test către o adresă proprie → marcați *Implementat și
verificat în producție*. Abia apoi setați `AW_MESSAGING_SANDBOX=false`.

## 4. SMS (Integrări → SMS)

* Cont **Twilio** (Account SID, Auth token) și expeditor: în România, de regulă un *Alphanumeric Sender ID*
  (ex. `AWCluj`) — verificați cu Twilio cerințele de înregistrare pentru România.
* Buget lunar și plafon de cost per campanie (Configurare → Setări → Marketing).
* Stare: **adaptor pregătit**, fără trimiteri reale.

## 5. Plăți online (Integrări → Plăți online)

* Cont **Stripe** al firmei, activat (verificarea firmei și a reprezentantului se face la Stripe).
* *Secret key* (`sk_live_…`; pentru test `sk_test_…`).
* Webhook în Stripe Dashboard → Developers → Webhooks:
  * URL: `https://anotherworldcluj.ro/api/webhooks/stripe`
  * evenimente: `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
    `checkout.session.async_payment_failed`, `checkout.session.expired`
  * secretul de semnare (`whsec_…`) se introduce în panou.
* Datele cardului se introduc exclusiv pe pagina Stripe; aplicația nu le vede și nu le stochează.
* Stare: **adaptor pregătit**; semnătura webhook-urilor, idempotența și rambursările sunt verificate în teste automate
  cu răspunsuri simulate, **nu** cu un cont Stripe real. Recomandare: o rezervare de test în modul *test* Stripe,
  apoi o plată reală de valoare mică și rambursarea ei, înainte de lansare.
* **De confirmat cu contabilul:** tratamentul încasărilor online cu cardul (factură / bon fiscal) — aplicația permite
  ambele fluxuri, dar nu decide regimul fiscal.

## 6. Asistent virtual (Integrări → Asistent virtual)

* Cont **Anthropic** (console.anthropic.com) al firmei, cu metodă de plată și **limită de cheltuieli** setată în consolă.
* Cheie API. Model implicit `claude-opus-5-5`, efort `medium` — se pot schimba din panou.
* Buget zilnic în aplicație: Configurare → Setări → Asistent AI (la depășire, asistentul trimite vizitatorul spre formular).
* Baza de cunoștințe: doar intrările **aprobate** (Asistent AI → Bază de cunoștințe) ajung la model.
* Stare: **adaptor pregătit**, testat doar cu un model simulat; nu s-au făcut apeluri reale.

## 7. RO e-Factura (Integrări → e-Factura)

1. **Certificat digital calificat** al reprezentantului legal (sau al persoanei împuternicite).
2. Înregistrare în **SPV** (Spațiul Privat Virtual ANAF) pentru firmă.
3. Înregistrarea unei aplicații OAuth în portalul ANAF (secțiunea pentru dezvoltatori), cu
   *callback URL* `https://anotherworldcluj.ro/admin-anaf/callback`. Se obțin *Client ID* și *Client secret*.
4. În panou: datele aplicației → mediul **test** → butonul **Autorizează la ANAF** (necesită certificatul în browser).
5. Transmiterea câtorva facturi în mediul test → verificarea răspunsurilor → trecerea pe **prod**.

Ce este verificat: XML-ul UBL 2.1 / CIUS-RO generat de aplicație a fost validat cu validatorul public ANAF pentru patru
cazuri cu date fictive (B2B, B2C, stornare, neplătitor de TVA — `docs/exemple-efactura/`).
Ce **nu** este verificat: autorizarea OAuth și transmiterea reală (necesită certificatul firmei). Stare: **adaptor pregătit**.

**De confirmat cu contabilul:** seria de facturare, obligația de raportare B2C, cotele de TVA pentru serviciile de agrement
(cotele instalate sunt marcate „de verificat cu contabilul”).

## 8. Casa de marcat (Financiar → Case de marcat)

* Modelul aparatului fiscal și service-ul autorizat.
* Corespondența cote TVA → grupe fiscale ale aparatului și codurile metodelor de plată (de la service).
* Un calculator la recepție, conectat la casa de marcat, pe care rulează agentul local (`tools/fiscal-agent/`) și
  programul producătorului care preia comenzile (puntea „file”).
* Stare: **adaptor pregătit**. Fluxul server ↔ agent a fost verificat cu un aparat simulat; **nu** a fost testat pe un aparat
  fiscal real. Până la conectare, bonurile se emit direct din casa de marcat, iar în aplicație se poate folosi simulatorul,
  care marchează clar „SIMULARE – NU ESTE BON FISCAL”.

## 9. Marketing și măsurare (Configurare → Setări → Analytics / Site)

| Serviciu | Ce trebuie |
|---|---|
| Google Tag Manager / GA4 | ID-urile containerului / proprietății; se încarcă **doar după** acordul pentru cookie-uri |
| Google Ads | ID `AW-…` și eticheta conversiei „rezervare confirmată” |
| Meta Pixel | ID-ul pixelului |
| Google Search Console | verificarea domeniului (DNS); trimiterea `https://anotherworldcluj.ro/sitemap.xml` |
| Google Business Profile | profilul locației (adresă, program, fotografii reale, link de rezervare) — se gestionează în contul Google |
| Cloudflare Turnstile (opțional) | cheie site + cheie secretă, anti-spam pe formulare |
| Rețele sociale | linkurile paginilor oficiale |

Nu promitem poziții în Google: site-ul are structura tehnică (HTML randat pe server, date structurate, sitemap, viteză),
dar poziționarea depinde de conținut, recenzii și concurență.

## 10. Juridic și conformitate

* Validarea documentelor juridice instalate în ciornă (termeni, politica de rezervare, confidențialitate, cookies, minori,
  vouchere). Punctele marcate „de validat” rămân vizibile în panou până la rezolvare.
* Persoana de contact pentru protecția datelor (e-mail dedicat).
* Acordul francizorului pentru folosirea mărcii, a logo-ului și a materialelor de prezentare a jocurilor; drepturile pentru
  fiecare imagine sunt consemnate în **Conținut site → Media** (o imagine fără drepturi cunoscute nu poate fi publicată).

## 11. Utilizatori

Pentru fiecare membru al echipei: nume, e-mail profesional, rol (manager, recepție, operator, marketing, contabil, editor).
Rolurile cu acces la date financiare sau de marketing (super admin, manager, contabil, marketing) folosesc obligatoriu
autentificare în doi pași.
