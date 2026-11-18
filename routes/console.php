<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Scheduled jobs. They need the server cron: php artisan schedule:run every minute.
 *
 * 4 Nov (F-12): Laravel normally starts each scheduled command as its own process (proc_open).
 * Hostinger can switch proc_open off; then those commands never ran. Without proc_open (or with
 * SCHEDULE_IN_PROCESS=true in .env) they run inside the schedule:run process instead, which
 * needs nothing extra. Where proc_open works, nothing changes.
 */
$inProcess = ! function_exists('proc_open') || filter_var(env('SCHEDULE_IN_PROCESS', false), FILTER_VALIDATE_BOOL);
$job = fn (string $command, array $args = []) => $inProcess
    ? Schedule::call(fn () => Artisan::call($command, $args))->name($command.($args ? ' '.json_encode($args) : ''))
    : Schedule::command($command, $args);

// Driver document expiry reminders.
$job('crm:driver-document-reminders')->dailyAt('08:00');

// Audit log retention (off unless "Keep the audit log for" is set in Business settings).
$job('crm:prune-audit-log')->dailyAt('03:15');

// Website bookings not paid in time are cancelled (30 Oct, Bilal: 24 hours by default).
$job('crm:release-website-holds')->everyTenMinutes()->withoutOverlapping();

// 4 Nov (Phase 2): queued emails and notifications. Each minute the worker sends what is waiting and
// stops (shared hosting has no long-running processes). Failed jobs are tried 3 times, then kept in failed_jobs.
if (config('queue.default') !== 'sync') {
    $job('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--max-time' => 50, '--backoff' => 30])
        ->everyMinute()->withoutOverlapping(5)->before(fn () => \App\Support\Notifications\QueueHealth::beat());
}

// 4 Nov (Phase 2): email log clean-up, and time-based notices (lead not accepted, quotation expiring,
// balance due, job starting soon, late return). Each notice is sent once.
$job('crm:prune-email-log')->dailyAt('03:30');
$job('crm:notification-reminders')->everyFiveMinutes()->withoutOverlapping();
$job('crm:send-announcements')->everyMinute()->withoutOverlapping();
$job('crm:prune-driver-data')->dailyAt('03:45'); // 4 Nov (15): locations kept 30 days

// 5 Nov (S5): UAE fuel prices change on the 1st. Accounts and Admin are reminded until they are set.
Schedule::call(fn () => \App\Support\Fuel\Fuel::remindPrices())->name('fuel-price-reminder')->monthlyOn(1, '09:10');
Schedule::call(fn () => \App\Support\Fuel\Fuel::remindPrices())->name('fuel-price-reminder-again')->monthlyOn(3, '09:10');

// 18 Nov: rental contracts (monthly invoices, ending reminders, auto-renew).
$job('crm:contract-tasks')->dailyAt('07:10')->withoutOverlapping();
