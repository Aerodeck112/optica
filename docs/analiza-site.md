# Inventarul site-ului actual (GEN-01, GEN-04)

Analiză realizată pe 17 octombrie 2026 din paginile publice și din API-ul public WordPress (`/wp-json/wp/v2/pages`). Site-ul live nu a fost modificat.

## Tehnologie
- WordPress 7.1.3 + Elementor 4.3.4.
- Versiunea maghiară este generată cu TranslatePress, cu prefixul `/hu/` și **aceleași slug-uri** ca în română.
- Nu există articole de blog publicate (API-ul `posts` returnează 0 articole).

## Pagini și URL-uri (de păstrat, SEO-02)

| Pagină | RO | HU |
|---|---|---|
| Acasă | `/` | `/hu/` |
| Servicii | `/servicii/` | `/hu/servicii/` |
| Puncte de lucru | `/puncte-lucru/` | `/hu/puncte-lucru/` |
| Medici | `/medici/` | `/hu/medici/` |
| Fabrica de lentile | `/fabrica-lentile/` | `/hu/fabrica-lentile/` |
| Contact | `/contact/` | `/hu/contact/` |
| Reduceri actuale | `/reduceri-actuale/` | `/hu/reduceri-actuale/` |
| Politica de cookies | `/politica-de-cookies/` | `/hu/politica-de-cookies/` |
| Politica de confidențialitate | `/privacy-policy/` | `/hu/privacy-policy/` |

URL-uri tehnice WordPress care vor primi redirecționare 301 sau 410: `/feed/`, `/comments/feed/`, `/wp-json/…`, `/xmlrpc.php`, `/wp-content/uploads/…` (imaginile migrate își păstrează calea, prin redirect către biblioteca media nouă).

## Identitate vizuală
- **Logo**: „OPTICA OPTOFARM” cu ochelari stilizați, albastru deschis pe fond transparent (`/wp-content/uploads/2026/04/Untitled-design-8-1.png`, 500×500 PNG).
- **Culori** (Elementor global colors):

| Rol | Culoare |
|---|---|
| Primară | `#004975` (albastru închis) |
| Accent | `#FFDF51` (galben) |
| Secundară (text titluri) | `#1A1A1A` |
| Text | `#444444` |
| Terțiară | `#95949C` |
| Fundal alternativ | `#F6F4F0` |
| Albastru deschis | `#E7F4FD`, `#C8DBE9` |
| Bej | `#ECE5D8` |
| Bordură | `#DAD9DC` |
| Albastru logo / link | `#5D9FCF` |

- **Tipografie**: Inter (Google Fonts, subset latin-ext, necesar pentru diacriticele românești și maghiare), titluri cu greutate 300, butoane 600. În noua platformă fontul va fi găzduit local (fără apel la Google, compatibil GDPR).
- Sloganuri existente: „Expertul vederii de 35 de ani!”, „Ochelari potriviți. Grijă reală. Pentru fiecare generație.”

## Locații publicate (8)

| Localitate | Adresă | Telefon | Program magazin |
|---|---|---|---|
| Târgu Mureș | Str. Poștei nr. 3 | +40 265 212 304 | L–V 08–21, S 09–14, D închis |
| Târgu Mureș | Piața Trandafirilor nr. 53 | +40 265 250 120 | L–V 08–20 |
| Târgu Mureș | Str. Republicii nr. 5 | +40 365 430 939 | L–V 08–20 |
| Târgu Mureș | Bd. 1 Decembrie 1918 nr. 49 | +40 265 263 351 | L–V 08–20 |
| Târgu Mureș | Str. Gheorghe Doja nr. 64-68 | +40 265 212 212 | L–V 08–20 |
| Târgu Mureș | Bd. 1 Decembrie 1918 nr. 182-184 (Fortuna) | +40 265 265 205 | L–V 08–20 |
| Reghin | Str. Școlii nr. 11 | +40 265 512 042 | L–V 08–18 |
| Sovata | Str. Principală nr. 196 | +40 265 577 282 | L–V 08–16 |

Email afișat pentru toate locațiile: adresa generală de pe site. Coordonatele GPS nu sunt publicate și trebuie furnizate sau geocodate cu acordul beneficiarului.

## Servicii publicate (pagina Servicii)
1. Prescripție ochelari și lentile de contact
2. Screening oftalmologic (copii și adulți)
3. Examinare la biomicroscop (lampă cu fantă)
4. Examinarea fundului de ochi (fotografie fund de ochi și OCT)
5. Ecografie oculară
6. Gonioscopie
7. Tonometrie (măsurarea tensiunii oculare)
8. OCT de unghi iridocornean și pahimetrie
9. Topografie corneană
10. Examen de câmp vizual
11. Examen ortoptic la sinoptofor
12. Managementul miopiei și tratamentul ambliopiei

Alte categorii afișate în blocul comun: Oftalmologie generală, Optometrie digitală, Infecții și leziuni oculare, Sindromul de ochi uscat, Îndepărtarea corpurilor străine, Refracție pentru ochelari. Pagina Medici menționează chirurgia cataractei prin facoemulsificare și terapia vizuală combinată cu psihologia („gimnastică oculară psiho-ortoptică”).

**Tarifele nu sunt publicate pe site.** Ele nu se inventează; câmpurile de preț rămân goale până la completarea lor de către beneficiar.

## Medici
Pagina `/medici/` **nu listează medici individuali**: conține un text general, un afiș și numărul de programare 0365 430 939. Profilurile medicilor se vor completa din CMS (vezi DECISIONS.md, N-06).

## Fabrica de lentile
Conține prezentarea fabricii (înființare 1991, tipuri de lentile, tratamente) și secțiunea B2B, cu contact separat (telefon +40 771 048 936 și adresă de email dedicată). Formularul B2B din noua platformă va genera leaduri de tip „B2B fabrică lentile” (CRM-01).

## Reduceri actuale
O campanie cu perioadă definită (15 august – 30 septembrie 2026, deja încheiată la data analizei). În noul CMS, ofertele au dată de început și de sfârșit și se ascund automat după expirare (CMS-02).

## Elemente legale
Logo-urile ANPC (SAL și SOL) apar în footer pe toate paginile și se păstrează. Paginile de cookies și confidențialitate se migrează ca pagini CMS. Conținutul lor trebuie revalidat de beneficiar (SEC-05).

## Imagini identificate
Imaginile se descarcă în biblioteca media locală la importul de conținut (GEN-01), cu text alternativ RO/HU completat în CMS. Imagini principale: fotografii din magazin și din producție, imagini pentru servicii (aparatură), afișul pentru medici, materialele campaniei și fotografiile din fabrică.

## Neconcordanțe
Vezi DECISIONS.md, secțiunea „Neconcordanțe constatate în surse” (N-01…N-07).
