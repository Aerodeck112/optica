# Progres — anotherworldcluj.ro

Situația la 24.10.2026. Documentul spune ce funcționează, cum a fost verificat și ce depinde de configurări sau conturi externe.
Nimic din ce este marcat mai jos drept „testat” nu a rulat încă pe serverul de producție: aplicația nu a fost instalată
pe găzduirea finală.

**Cum se citesc stările integrărilor**

| Stare | Înseamnă |
|---|---|
| Implementat și testat în producție | funcționează cu contul real al firmei, pe serverul live |
| Testat în sandbox | verificat cu contul de test al furnizorului (fără efect real) |
| Adaptor pregătit | codul este scris și acoperit de teste automate cu răspunsuri simulate; lipsesc contul/credențialele pentru un test real |
| Neconfigurat | funcționalitatea există, dar nu are încă datele necesare (ID-uri, chei) |
| Blocat | nu poate avansa fără o decizie sau un document extern |

Rezultatele obținute cu simulatoare (plăți, casă de marcat, asistent AI) **nu** reprezintă validare în producție.

---

## 1. Integrări externe

| Integrare | Stare | Ce este verificat | Ce lipsește |
|---|---|---|---|
| E-mail către clienți (SMTP sau Brevo) | **Adaptor pregătit** | generarea mesajelor, șabloane, jurnal, retrimiteri, sandbox în afara producției (teste automate) | cont/căsuță, DNS (SPF/DKIM/DMARC), test real |
| SMS (Twilio) | **Adaptor pregătit** | text, segmente, estimare cost, plafon, dezabonare (teste automate) | cont Twilio, expeditor înregistrat |
| Plăți online (Stripe Checkout) | **Adaptor pregătit** | sesiune doar pentru avans, semnătura webhook, evenimente duplicate, sumă greșită respinsă, rambursare (teste cu răspunsuri simulate) | cont Stripe, cheie și secret webhook, plată reală de test |
| Asistent virtual (Claude API) | **Adaptor pregătit** | bucla cu unelte, confirmarea rezervării, limite, buget, refuzuri (teste cu model simulat) | cheie API, limită de cheltuieli în consola Anthropic |
| RO e-Factura (ANAF) | **Adaptor pregătit** | XML UBL/CIUS-RO validat cu **validatorul public ANAF** pentru B2B, B2C, stornare, neplătitor TVA (date fictive); transmitere și interogare stare (teste simulate) | certificat digital, SPV, aplicație OAuth ANAF, test pe mediul ANAF „test” |
| Facturi primite prin SPV | **Adaptor pregătit** | preluarea în facturi de achiziție, identificare furnizor după CUI (teste) | aceeași autorizare ANAF |
| Casă de marcat (agent local) | **Adaptor pregătit** | protocol HMAC, livrare unică, timeout → „incert”, reconciliere; rulare cap-coadă agent ↔ server cu aparat **simulat** | aparatul real, service autorizat, puntea producătorului |
| Google Tag Manager / GA4 / Google Ads / Meta Pixel | **Neconfigurat** | încărcare doar după acordul pentru cookie-uri; CSP adaptat automat | ID-urile conturilor |
| Cloudflare Turnstile (anti-spam) | **Neconfigurat** | verificare pe server când este activat | chei Turnstile (opțional) |
| Google Search Console, Google Business Profile | în afara aplicației | sitemap, date structurate, `robots.txt` corect în producție | verificarea domeniului, profilul locației |

Detalii despre conturi și pași: `docs/CONTURI-EXTERNE.md`.

---

## 2. Module funcționale

Legendă: **T** = acoperit de teste automate · **B** = parcurs în browser (capturi de ecran verificate) · **E** = depinde de date sau conturi externe.

### Site public
| Funcționalitate | Stare | |
|---|---|---|
| Pagini pe blocuri (18 tipuri), meniuri, FAQ, noutăți, galerie, documente juridice versionate | funcționează | T B |
| Catalog jocuri cu filtre; jocurile apar doar după confirmarea pentru Cluj | funcționează | B, E (confirmarea jocurilor) |
| Rezervare online pas cu pas, cu ore libere reale, blocare temporară a orei, cerere când pachetul nu are preț/capacitate | funcționează | T B, E (prețuri, program) |
| Plata avansului online, pagina „Gestionează rezervarea” (anulare/reprogramare după reguli, calendar ICS, cod QR check-in) | funcționează | T, E (Stripe) |
| Cont client cu link pe e-mail (fără parolă), preferințe, export date, cerere de ștergere | funcționează | T, E (e-mail) |
| Asistent virtual cu unelte (disponibilitate, ofertă, pregătirea rezervării, căutarea propriei rezervări) | funcționează | T (model simulat), E (cheie API) |
| Cookie-uri cu consimțământ granular, analytics încărcat doar după acord | funcționează | B, E (ID-uri) |
| SEO tehnic: HTML randat pe server, `hreflang`, sitemap, date structurate, redirecturi 301 la schimbarea adresei | funcționează | T B |
| Pagini de eroare proprii (404, 410, 419, 429, 500, 503) | funcționează | T B |
| Limbi: română publică; engleză și maghiară traduse (texte de interfață), **nepublicate** până la verificare | parțial | T (chei și variabile identice), E (verificare de vorbitori nativi, traducerea conținutului din CMS) |

### Recepție și rezervări
| Funcționalitate | Stare | |
|---|---|---|
| Motor de rezervări fără suprapuneri (blocare în baza de date, verificat cu procese paralele reale) | funcționează | T |
| Calendar zi/săptămână/lună, blocări de intervale (inclusiv recurente) | funcționează | T B |
| Rezervare de la recepție, confirmare, reprogramare, anulare, neprezentare, finalizare, istoric | funcționează | T B |
| Check-in cu cod QR (camera tabletei) sau cod | funcționează | B |
| Încasare la recepție (numerar, card POS, transfer), protecție la dublu-clic, stare plată recalculată | funcționează | T B |
| Remindere automate, recalculate la reprogramare, oprite la anulare | funcționează | T, E (e-mail/SMS) |

### CRM, marketing, fidelizare
| Funcționalitate | Stare | |
|---|---|---|
| Clienți: deduplicare, note cu vizibilitate restrânsă, consimțăminte cu istoric și dovadă, unirea duplicatelor, export, anonimizare | funcționează | T B |
| Cereri evenimente (aniversări, companii): flux pe etape, oferte, conversie în rezervare | funcționează | T B |
| Segmente (doar cu consimțământ), campanii e-mail/SMS cu test obligatoriu, ore de liniște, plafon de frecvență și de cost | funcționează | T B, E (furnizori) |
| Importuri de contacte cu dovada consimțământului | funcționează | B |
| Puncte de fidelitate (registru, expirare FIFO), niveluri, recompense, vouchere cadou tipăribile | funcționează | T B |

### Financiar și stocuri
| Funcționalitate | Stare | |
|---|---|---|
| Facturi: ciorne, emitere cu numerotare continuă (verificată cu procese paralele), PDF, stornare, avans și regularizare, TVA pe cote cu rotunjire conformă | funcționează | T B, E (serie, date firmă, cote validate de contabil) |
| e-Factura: generare, validare locală, transmitere, interogare automată, termen legal afișat | funcționează până la ANAF | T, E (autorizare ANAF) |
| Plăți online și rambursări cu aprobare | funcționează | T, E (Stripe) |
| Bonuri fiscale prin agent local, simulator marcat „SIMULARE – NU ESTE BON FISCAL”, reconciliere | funcționează până la aparat | T, E (casa de marcat) |
| Facturi de la furnizori (manual sau din SPV), recepții NIR tipăribile, stoc pe gestiuni, consum, casare, inventar | funcționează | T B |
| Export pentru contabil: facturi, încasări, achiziții (CSV) și arhivă PDF + XML | funcționează | T B |

### Administrare și sistem
| Funcționalitate | Stare | |
|---|---|---|
| 8 roluri cu permisiuni, autentificare în doi pași obligatorie pentru rolurile sensibile, conturi fără afișarea parolelor | funcționează | T B |
| Setări locație și firmă, „Pregătire lansare” cu ce lipsește | funcționează | B |
| Integrări cu credențiale criptate, test de conexiune, stări | funcționează | B |
| Jurnal de audit, jurnal mesaje | funcționează | B |
| Media: variante optimizate, eliminarea metadatelor, punct focal, drepturi și acord pentru minori obligatorii la publicare | funcționează | T B |
| Backup bază de date + fișiere, criptat, verificat automat; restaurare testată | funcționează | T, E (copie externă, parola de backup) |
| Retenția datelor (ștergeri programate) | funcționează | — (rulare zilnică; recomandat `aw:retention --dry-run` după configurare) |

---

## 3. Calitate

* **125 de teste automate** (962 de verificări), toate trecute; rulează la fiecare modificare în GitHub Actions pe MariaDB 10.11,
  împreună cu verificarea stilului de cod și auditul dependențelor (fără vulnerabilități cunoscute la ultima rulare).
  Zone acoperite: rezervări și disponibilitate (inclusiv concurență și schimbarea orei), plăți, facturi, e-Factura, fiscal,
  stocuri, fidelizare, CRM, CMS, campanii, notificări, cont client, asistent AI, acces pe roluri, backup, traduceri, pagini de eroare.
* **Concurență** verificată cu procese PHP paralele reale (nu simulări) pentru: rezervarea aceleiași ore, utilizarea unui voucher,
  puncte de fidelitate, numerotarea facturilor, consumul de stoc. Testul de stoc a descoperit un deadlock, corectat.
* **Lighthouse** (mobil, rețea și procesor simulate, server local de dezvoltare):

  | Pagină | Performanță | Accesibilitate | Bune practici | LCP | CLS |
  |---|---|---|---|---|---|
  | Acasă | 90–94 | 100 | 100 | 2,3 s | 0 |
  | Jocuri VR | 99 | 100 | 100 | 1,9 s | 0 |
  | Rezervare | 99 | 100 | 100 | 2,0 s | 0 |

  Scorul SEO din mediul local (69) este cauzat exclusiv de `robots.txt`, care blochează intenționat indexarea în afara producției.
  Valorile pe serverul final depind de găzduire și trebuie remăsurate după instalare.
* **Instalare curată verificată** în modul `production` pe o bază nouă: migrații, date de bază, cont admin, cache-uri, pagini principale
  (fără date demonstrative, fără „De completat” pe site).

---

## 4. Ce rămâne de făcut înainte de lansare

1. Instalarea pe cPanel (`docs/INSTALARE-CPANEL.md`) și remăsurarea performanței pe serverul real.
2. Datele firmei și ale locației, programul, jocurile confirmate, pachetele cu prețuri — **de completat** de operator.
3. Validarea juridică a documentelor (instalate în ciornă) și a textelor de consimțământ.
4. Decizii cu contabilul: seria de facturare, cotele de TVA, raportarea B2C în e-Factura, regimul încasărilor online.
5. Conturile externe (e-mail, SMS, Stripe, Anthropic, ANAF, casa de marcat, analytics) și testarea fiecăreia până la starea
   „Implementat și testat în producție”.
6. Fotografii reale ale arenei din Cluj (cu drepturi); confirmarea cu franciza a acordurilor pentru imaginile cu minori.
7. Verificarea traducerilor EN/HU de către vorbitori nativi și traducerea conținutului din CMS, apoi `AW_PUBLIC_LOCALES`.

## 5. Limitări cunoscute

* Asistentul AI răspunde în bloc (fără afișare progresivă), potrivit găzduirii partajate.
* Conexiunea cu casa de marcat necesită o punte specifică modelului aparatului; agentul livrat acoperă protocolul cu serverul.
* Calculul termenului e-Factura nu ține cont de sărbătorile legale (afișează zilele lucrătoare luni–vineri).
* Nu s-a efectuat un test de penetrare extern (vezi `docs/SECURITATE.md`).

## 6. Materiale folosite

* Din Drive-ul francizei: 73 de imagini (44 din jocuri, 18 fotografii din alte locații ale rețelei, 6 dintre acestea cu minori,
  5 artă de brand), catalogul de jocuri și materialele video de prezentare. Fiecare imagine are în biblioteca media sursa
  (ID-ul fișierului din Drive) și drepturile cunoscute; fotografiile din alte locații sunt etichetate pe site
  „Fotografii din rețeaua Another World”, iar imaginile din jocuri „Imagini din jocuri” — niciuna nu este prezentată drept arena din Cluj.
* Experiențele sunt exclusiv VR; nu există referiri la LED floor / pixel floor (asistentul AI are instrucțiune explicită).
* Valorile publicate de franciză care diferă între surse (vârste, număr de jucători, durate) sunt păstrate doar ca referință internă
  și nu apar pe site până la confirmare.

## 7. Dependențe adăugate în acest proiect

* `anthropic-ai/sdk` — clientul oficial pentru Claude API (asistentul virtual).
* `laravel/boost` (doar dezvoltare) — instrumente pentru dezvoltare; nu se instalează în producție (`composer install --no-dev`).

## 8. Documente

| Document | Pentru cine |
|---|---|
| `docs/INSTALARE-CPANEL.md` | cine instalează și actualizează aplicația |
| `docs/CONTURI-EXTERNE.md` | administratorul firmei: ce conturi și date trebuie obținute |
| `docs/GHID-ECHIPA.md` | recepție, manager, contabil, editor, marketing |
| `docs/SECURITATE.md` | administrator, auditor |
| `docs/casa-de-marcat.md` | instalarea agentului casei de marcat |
| `docs/ARHITECTURA.md` | dezvoltatori |
