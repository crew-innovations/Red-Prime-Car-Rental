#!/bin/bash
# Updates the app on Hostinger shared hosting after new files were uploaded.
# Usage (from the project folder):  bash scripts/deploy-hostinger.sh
#         add sample data:           bash scripts/deploy-hostinger.sh --demo
#         without the DB backup:     bash scripts/deploy-hostinger.sh --skip-backup
set -e
cd "$(dirname "$0")/.."

step() { echo; echo "==> $1"; }

step "Installing libraries (1-2 minutes)"
composer install --no-dev --optimize-autoloader --no-scripts --no-interaction
# PDF library (added 28 Oct). Installed once if the server's lock file does not have it yet.
if [ ! -d vendor/barryvdh/laravel-dompdf ]; then
    step "Adding the PDF library (one time)"
    composer update barryvdh/laravel-dompdf --with-dependencies --no-dev --optimize-autoloader --no-scripts --no-interaction
fi

step "Registering packages"
# Old cached config / routes would hide new settings until rebuilt (rebuilt by "optimize" below).
rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/events.php
php artisan package:discover
php artisan filament:upgrade

step "Backing up the database before changes"
# Reads the DB settings from .env; the password goes into a private temp file, never the command line.
envval() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
mkdir -p storage/app/backups
BACKUP="storage/app/backups/db-$(date +%Y%m%d-%H%M%S).sql.gz"
CNF=$(mktemp); chmod 600 "$CNF"
printf "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n" "$(envval DB_USERNAME)" "$(envval DB_PASSWORD)" "$(envval DB_HOST)" "$(envval DB_PORT)" > "$CNF"
if command -v mysqldump >/dev/null 2>&1 && mysqldump --defaults-extra-file="$CNF" --single-transaction --no-tablespaces "$(envval DB_DATABASE)" | gzip > "$BACKUP" && [ -s "$BACKUP" ]; then
    rm -f "$CNF"
    echo "Saved $BACKUP"
else
    rm -f "$CNF" "$BACKUP"
    if [ "$1" != "--skip-backup" ] && [ "$2" != "--skip-backup" ]; then
        echo "Backup failed, so the database was NOT changed. Fix the backup, or run again with --skip-backup."
        exit 1
    fi
    echo "Backup skipped (--skip-backup)."
fi

step "Updating the database"
php artisan migrate --force

step "Moving booking photos / agreements to private storage"
php artisan crm:secure-booking-files

if [ "$1" == "--demo" ] || [ "$2" == "--demo" ]; then
    step "Adding sample data"
    php artisan db:seed --class=DemoDataSeeder --force
fi

step "Refreshing caches"
php artisan optimize:clear
php artisan optimize

echo
echo "DONE - open /crm in the browser."
