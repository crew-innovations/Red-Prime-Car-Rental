#!/bin/bash
# Updates the app on Hostinger shared hosting after new files were uploaded.
# Usage (from the project folder):  bash scripts/deploy-hostinger.sh
#         add sample data:           bash scripts/deploy-hostinger.sh --demo
set -e
cd "$(dirname "$0")/.."

step() { echo; echo "==> $1"; }

step "Installing libraries (1-2 minutes)"
composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

step "Registering packages"
php artisan package:discover
php artisan filament:upgrade

step "Updating the database"
php artisan migrate --force

if [ "$1" == "--demo" ]; then
    step "Adding sample data"
    php artisan db:seed --class=DemoDataSeeder --force
fi

step "Refreshing caches"
php artisan optimize:clear
php artisan optimize

echo
echo "DONE - open /crm in the browser."
