# Optica Optofarm – platformă integrată

Site public, administrare de conținut, CRM medical multi-locație, programări online, comunicare (email, WhatsApp, SMS) și rapoarte, în română și maghiară. Platforma rulează pe hosting cPanel (PHP + MySQL/MariaDB + cron).

| Document | Conținut |
|---|---|
| [REQUIREMENTS.md](REQUIREMENTS.md) | cerințele și corespondența cu caietul de sarcini |
| [ARCHITECTURE.md](ARCHITECTURE.md) | arhitectura și modelul de date |
| [PERMISSIONS.md](PERMISSIONS.md) | roluri și permisiuni |
| [PROGRESS.md](PROGRESS.md) | ce este implementat, testat și rămas de făcut |
| [DECISIONS.md](DECISIONS.md) | decizii, presupuneri, neconcordanțe de confirmat |
| [DEPLOYMENT.md](DEPLOYMENT.md) | instalare și operare cPanel |
| [CHANGELOG.md](CHANGELOG.md) | modificările versiunilor |
| [docs/analiza-site.md](docs/analiza-site.md) | inventarul site-ului actual |
| [docs/analiza-evolvo.md](docs/analiza-evolvo.md) | analiza fotografiilor de referință Evolvo |

## Dezvoltare
```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan test
```
Testele necesită MySQL/MariaDB (`optica_test`), deoarece verifică și concurența reală a rezervărilor.
