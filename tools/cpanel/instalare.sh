#!/bin/bash
# Instalare pe cPanel FĂRĂ Terminal. Se rulează o singură dată, dintr-un Cron Job:
#   bash $HOME/anotherworldcluj/tools/cpanel/instalare.sh adresa@domeniu.ro "Nume Prenume"
# Rezultatul se scrie în $HOME/aw-instalare.log (se citește din File Manager).
# Pentru a rula din nou (de ex. după o eroare corectată), ștergeți fișierul $HOME/aw-instalare.gata.

APP="$HOME/anotherworldcluj"
LOG="$HOME/aw-instalare.log"
DONE="$HOME/aw-instalare.gata"
ADMIN_EMAIL="$1"
ADMIN_NAME="$2"

[ -f "$DONE" ] && exit 0
exec >>"$LOG" 2>&1
echo "=== Început: $(date) ==="

# PHP 8.3+ pentru linia de comandă (calea diferă de la o găzduire la alta)
PHP=""
for p in /opt/cpanel/ea-php85/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php \
         /opt/alt/php85/usr/bin/php /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /usr/local/bin/php /usr/bin/php; do
  if [ -x "$p" ] && "$p" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' 2>/dev/null; then PHP="$p"; break; fi
done
if [ -z "$PHP" ]; then
  echo "EROARE: nu am găsit PHP 8.3 sau mai nou pentru linia de comandă. Cereți firmei de găzduire calea către PHP 8.3 CLI."
  touch "$DONE"; exit 1
fi
echo "PHP folosit: $PHP (versiunea $("$PHP" -r 'echo PHP_VERSION;'))"
echo "$PHP" > "$HOME/aw-php-cale.txt"

cd "$APP" || { echo "EROARE: nu există folderul $APP"; touch "$DONE"; exit 1; }
[ -f .env ] || { echo "EROARE: lipsește fișierul .env (copiați .env.example ca .env și completați-l)"; touch "$DONE"; exit 1; }

# marcăm imediat rularea, ca un cron care pornește din nou peste un minut să nu repete pașii
touch "$DONE"

echo "--- Extensii PHP lipsă (dacă apare ceva aici, activați extensiile în Select PHP Version):"
for ext in pdo_mysql mbstring intl gd zip fileinfo openssl curl exif sodium; do
  "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);" || echo "  LIPSEȘTE: $ext"
done

echo "--- Cheia aplicației"
grep -q '^APP_KEY=base64:' .env && echo "există deja" || "$PHP" artisan key:generate --force --no-interaction

echo "--- Baza de date"
"$PHP" artisan migrate --force --no-interaction || { echo "EROARE la crearea tabelelor. Verificați DB_DATABASE, DB_USERNAME, DB_PASSWORD din .env, apoi ștergeți $DONE."; exit 1; }
"$PHP" artisan db:seed --force --no-interaction || { echo "EROARE la datele inițiale."; exit 1; }

echo "--- Legătura pentru imagini (public/storage)"
[ -e public/storage ] && echo "există deja" || ln -s ../storage/app/public public/storage

echo "--- Optimizare"
"$PHP" artisan optimize
"$PHP" artisan filament:optimize

chmod 600 .env
chmod -R ug+rwX storage bootstrap/cache

if [ -n "$ADMIN_EMAIL" ]; then
  echo "--- Cont administrator"
  "$PHP" artisan aw:create-admin "$ADMIN_EMAIL" "${ADMIN_NAME:-Administrator}" --role=super_admin --show-link
fi

echo "=== GATA: $(date) ==="
echo "Ștergeți acum Cron Job-ul de instalare din cPanel."
