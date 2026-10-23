# Arhitectura platformei Another World Cluj

Document de referință pentru dezvoltatori. Descrie deciziile tehnice, separarea modulelor și modelul de date.
Starea fiecărei componente (ce funcționează, ce e testat, ce depinde de configurări externe) este în `docs/PROGRES.md`.

## 1. Decizii tehnice

| Decizie | Alegere | Motiv |
|---|---|---|
| Framework | Laravel 13 (PHP ≥ 8.3) | Versiune stabilă, suport securitate până în T1 2028; rulează pe cPanel fără proces permanent. |
| Bază de date | MySQL 8 / MariaDB ≥ 10.6 | Disponibile pe orice cPanel; `SELECT … FOR UPDATE` pentru blocări tranzacționale. |
| Panou administrare | Filament 5 (Livewire 4) | Formulare, tabele, filtre, căutare globală, MFA; nu cere Node pe server. |
| Site public | Blade randat server-side + CSS/JS propriu construit cu Vite | HTML complet la prima cerere (SEO), JavaScript încărcat pe module, doar unde e nevoie. |
| Cozi | driver `database` | Fără Redis. Procesare prin cron (`schedule:run` în fiecare minut) cu worker scurt (`--stop-when-empty --max-time`). |
| Cache / sesiuni | `database` (sau `file`) | Compatibil cu hosting partajat. |
| Sume de bani | întregi în bani (minor units) + `brick/math` pentru calcule TVA | Fără erori de virgulă mobilă; fără dependență de `bcmath`. |
| Timp | stocare UTC, afișare `Europe/Bucharest` | Schimbarea orei se tratează în conversie; testele acoperă zilele de tranziție. |
| Imagini | variante AVIF/WebP/JPEG generate la build pentru asset-urile statice; la upload în CMS: WebP + JPEG (AVIF dacă GD îl suportă) | Hosting-ul nu garantează AVIF în GD. |
| Fonturi | Unbounded (titluri) + Onest (text), găzduite local | Fără cereri către terți înainte de consimțământ; diacritice ș ț ă î â ő ű verificate. |
| AI | Claude API apelat exclusiv server-side, cu unelte (tools) validate | Cheile nu ajung în browser; modelul nu are acces la SQL sau la date arbitrare. |

### Când este nevoie de VPS
Varianta cPanel acoperă traficul unei singure locații. Recomandăm VPS dacă:
- volumul de notificări/campanii depășește ce poate procesa un worker pornit din minut în minut (≈ zeci de mii de mesaje/zi);
- se dorește asistentul AI cu streaming (răspuns afișat progresiv) — pe shared hosting răspunsul vine în bloc;
- hostingul dezactivează `proc_open`/`exec` sau limitează timpul CLI sub 60 s;
- agentul fiscal trebuie să comunice cu serverul prin conexiune permanentă (varianta implicită folosește polling, care funcționează pe cPanel).

## 2. Module (monolit modular)

Codul de domeniu stă în `app/Modules/<Modul>`; panoul în `app/Filament`; site-ul în `app/Http/Controllers/Site` + `resources/views/site`.

| Modul | Responsabilitate |
|---|---|
| `Core` | setări locație și integrări (criptate), program și excepții, utilitare timp/bani, roluri |
| `Cms` | pagini pe blocuri, revizii, media, meniuri, FAQ, articole, bannere, documente juridice, redirecturi |
| `Catalog` | jocuri, genuri, pachete, servicii suplimentare, reguli de preț |
| `Booking` | resurse, disponibilitate, blocări temporare, rezervări, istoric, check-in, ICS |
| `Crm` | clienți, deduplicare/unire, consimțăminte, note, pipeline evenimente, sarcini |
| `Notifications` | șabloane, adaptoare e-mail/SMS, remindere, jurnal livrare, webhook-uri |
| `Marketing` | segmente, campanii, liste de excludere, importuri cu dovada permisiunii |
| `Loyalty` | registru puncte, niveluri, recompense, recomandări, vouchere |
| `Ai` | asistent virtual, bază de cunoștințe aprobată, unelte, buget, jurnal minimizat |
| `Finance` | facturi, proforme, storno, avansuri, încasări, rambursări, TVA, serii, export contabil |
| `Integrations` | e-Factura ANAF, procesatori plăți, agent casă de marcat |
| `Inventory` | furnizori, facturi primite, articole, gestiuni, recepții (NIR), stocuri, inventar |
| `Audit` | jurnal de audit, backup/restaurare, retenție date |

Regula de dependență: modulele comunică prin servicii publice (`*Service`, `Actions`), nu prin modificarea directă a tabelelor altui modul. Toate canalele de rezervare (site, AI, recepție, admin) apelează `Booking\BookingService`.

## 3. Motorul de rezervări

### Concepte
- **Resursă** (`resources`): arena sau zone ale arenei (ierarhie `parent_id`). Ocuparea unei resurse blochează strămoșii și descendenții (rezervarea întregii arene blochează zonele; rezervarea unei zone blochează rezervarea exclusivă a arenei).
- **Pachet** (`packages`): definește durata slotului (instructaj + joc + pregătire), modul sesiunii (`private` = exclusiv, `shared` = pe capacitate), participanți min/max, preț, avans, modul de rezervare (`instant` / `request`).
- **Ocupare** (`booking_resources`): intervalul efectiv blocat, inclusiv pauzele dinainte/după.
- **Blocare temporară** (`slot_holds`): ține slotul cât timp vizitatorul completează formularul sau plătește; expiră automat.
- **Blocări interne** (`resource_blocks`): mentenanță, evenimente interne, serii recurente.

### Garanția anti-suprapunere
Orice operație care ocupă timp (hold, rezervare, reprogramare, blocare internă) rulează în tranzacție și:
1. blochează cu `SELECT … FOR UPDATE` rândurile resurselor afectate (familia resursei, ordonate după id → fără deadlock);
2. verifică suprapunerile cu rezervări active, hold-uri neexpirate și blocări interne;
3. verifică capacitatea pentru sesiunile partajate;
4. inserează și face commit.

Două cereri simultane pentru același slot sunt serializate de blocarea rândului resursei; a doua vede ocuparea și primește „slot indisponibil”. Testul `BookingConcurrencyTest` pornește procese PHP paralele reale pe MariaDB.

### Stări
- Rezervare: `request` (cerere, nu ocupă arena) → `pending` (ocupă până la `expires_at`) → `confirmed` → `checked_in` → `completed`; ramuri `cancelled`, `no_show`, `expired`.
- Plată (separat): `unpaid`, `deposit_paid`, `partially_paid`, `paid`, `partially_refunded`, `refunded`.
- Facturare (separat): `not_invoiced`, `proforma`, `invoiced`.

Dacă pachetul nu are preț sau capacitate configurate, fluxul public creează automat o **cerere de disponibilitate**, nu o confirmare.

## 4. Model de date (rezumat)

Tabelele sunt definite în `database/migrations`. Grupuri principale:

- **Core**: `users`, `roles/permissions` (spatie), `settings`, `integrations`, `opening_hours`, `schedule_exceptions`, `audit_logs`, `sessions`.
- **CMS**: `pages` (+ `page_revisions`), `media` (+ `media_usages`), `menus`, `menu_items`, `faqs`, `posts`, `banners`, `legal_documents` (versionate), `redirects`, `gallery_items`, `testimonials`.
- **Catalog**: `game_genres`, `games`, `packages`, `addons`, `package_addon`, `price_rules`.
- **Booking**: `resources`, `resource_blocks`, `slot_holds`, `bookings`, `booking_items`, `booking_resources`, `booking_events`.
- **CRM**: `customers`, `customer_notes`, `consents`, `consent_events`, `suppressions`, `leads`, `lead_activities`, `tasks`.
- **Notifications**: `notification_templates`, `scheduled_notifications`, `message_logs`, `webhook_events`.
- **Marketing**: `segments`, `campaigns`, `campaign_recipients`, `contact_imports`.
- **Loyalty**: `loyalty_ledger`, `loyalty_tiers`, `rewards`, `referrals`, `vouchers`, `voucher_transactions`.
- **AI**: `ai_conversations`, `ai_messages`, `ai_knowledge_entries`, `ai_usage`.
- **Finance**: `vat_rates`, `document_series`, `invoices`, `invoice_lines`, `payments`, `refunds`, `payment_allocations`, `efactura_submissions`, `efactura_inbox`, `fiscal_devices`, `fiscal_receipts`.
- **Inventory**: `suppliers`, `units`, `items`, `warehouses`, `purchase_invoices`, `purchase_invoice_lines`, `goods_receipts`, `goods_receipt_lines`, `stock_movements`, `stock_levels`, `inventory_counts`, `inventory_count_lines`.

Câmpurile traductibile sunt JSON (`{"ro": …, "en": …, "hu": …}`); fiecare entitate publicabilă are `published_locales`, iar `hreflang` și sitemap-ul includ doar limbile publicate.

## 5. Medii

| Mediu | Scop | Particularități |
|---|---|---|
| development | local | `MAIL/SMS` în sandbox (jurnal), date demonstrative fictive (`DemoSeeder`) |
| staging | verificare înainte de producție | `noindex` global, autentificare HTTP opțională, integrări în mod test, destinatari de test |
| production | live | integrări reale după configurare; seed doar date de bază (`ProductionSeeder`) |

Seed-urile demonstrative nu pot rula în producție (comanda refuză).

## 6. Securitate pe scurt
Permisiuni server-side prin politici (`app/Policies`) pentru fiecare model; MFA obligatoriu pentru rolurile administrative; CSP cu nonce; secrete criptate în baza de date; audit pentru modificări sensibile. Detalii în `docs/SECURITATE.md`.
