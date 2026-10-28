<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Driver document expiry reminders (needs the server cron: php artisan schedule:run every minute).
\Illuminate\Support\Facades\Schedule::command('crm:driver-document-reminders')->dailyAt('08:00');

// Audit log retention (off unless "Keep the audit log for" is set in Business settings).
\Illuminate\Support\Facades\Schedule::command('crm:prune-audit-log')->dailyAt('03:15');
