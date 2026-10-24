# Another World Cluj — anotherworldcluj.ro

Platforma arenei de realitate virtuală Another World din Cluj-Napoca: site public, rezervări online, recepție,
CRM, marketing, fidelizare, asistent virtual, facturare cu RO e-Factura, plăți, stocuri și casă de marcat.
Laravel 13 + Filament 5, gândită pentru găzduire cPanel.

| Document | Conținut |
|---|---|
| [docs/PROGRES.md](docs/PROGRES.md) | ce funcționează, ce este testat, ce depinde de configurări externe |
| [docs/INSTALARE-CPANEL.md](docs/INSTALARE-CPANEL.md) | instalare, cron, actualizări, backup și restaurare |
| [docs/CONTURI-EXTERNE.md](docs/CONTURI-EXTERNE.md) | conturile și datele de obținut înainte de lansare |
| [docs/GHID-ECHIPA.md](docs/GHID-ECHIPA.md) | utilizarea panoului, pe roluri |
| [docs/SECURITATE.md](docs/SECURITATE.md) | măsuri de securitate și protecția datelor |
| [docs/ARHITECTURA.md](docs/ARHITECTURA.md) | decizii tehnice și model de date |
| [docs/casa-de-marcat.md](docs/casa-de-marcat.md) | agentul local pentru casa de marcat |

## Dezvoltare locală

```bash
composer install && npm ci && npm run build
cp .env.example .env   # APP_ENV=local, APP_DEBUG=true, AW_MESSAGING_SANDBOX=true, baza de date locală
php artisan key:generate
php artisan migrate --seed          # AW_SEED_DEMO=true în .env adaugă date demonstrative fictive
php artisan aw:create-admin admin@example.test "Administrator" --show-link
php artisan serve
php artisan test --compact
```

În afara producției, mesajele către clienți nu sunt trimise (rămân în jurnal, cu starea „sandbox”), cu excepția
adreselor din `AW_TEST_EMAILS` / `AW_TEST_PHONES`.
