# Red Prime CRM

Website and internal CRM for **RED PRIME RENTALS** (car rental, yacht rental, yacht trips).
Built by Crew Innovations.

The CRM is the single source of truth for the website: cars, yachts and trips are managed
in the CRM, and the public website reads them directly from the same database.

## Stack

- PHP 8.2+ (8.3 on the server), Laravel 11, MySQL 8
- Filament 3 (CRM admin panel at `/crm`)
- spatie/laravel-permission (roles), spatie/laravel-medialibrary (images)
- Blade + Tailwind for the public website (Milestone 3)

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# create the MySQL database named in .env (DB_DATABASE), then:
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan migrate --seed
php artisan storage:link

php artisan serve
```

Open http://localhost:8000/crm and log in with `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env`.

## Milestones

| Milestone | Scope |
|---|---|
| M1 | CRM foundation, Car Rental, Yacht Rental, Yacht Trips, Customers, Leads |
| M2 | Pipelines, bookings, quotations, payments, operations, documents, tasks, automation, reports, roles, website controls |
| M3 | Public website, website–CRM connection, social integrations, testing, deployment |

## Key rules

- An item shows on the website only when it is **published** and its **category is enabled**
  (`publiclyVisible()` scope in `App\Models\Concerns\PublishesToWebsite`).
- Disabling a category hides it from the website but never deletes its records.
