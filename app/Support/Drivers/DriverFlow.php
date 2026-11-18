<?php

namespace App\Support\Drivers;

use App\Models\AuditLog;
use App\Models\BookingJob;
use App\Models\DriverAction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a driver does with a job from the phone (4 Nov, brief 15): accept, decline with a reason,
 * on the way / arrived, running late. Every action can carry the phone's own id, so the same
 * action sent twice on a weak signal is done once. The office and, when switched on, the
 * customer hear about it. Drivers never record money here.
 */
class DriverFlow
{
    public const ACTIONS = ['acknowledged', 'on_the_way', 'arrived', 'decline', 'late'];

    public const LATE_STEPS = [10, 15, 20, 30, 45, 60, 90];

    /**
     * Runs one action once. Returns 'done', or 'duplicate' when this phone already sent it.
     *
     * @param  array{reason?: string, minutes?: int}  $data
     */
    public static function run(BookingJob $job, User $driver, string $action, array $data = [], ?string $clientId = null): string
    {
        abort_unless((int) $job->driver_id === (int) $driver->id && $driver->is_active, 403);
        abort_unless(in_array($action, self::ACTIONS, true), 422);

        return DB::transaction(function () use ($job, $driver, $action, $data, $clientId) {
            if ($clientId) {
                try {
                    DriverAction::create(['client_action_id' => mb_substr($clientId, 0, 64), 'user_id' => $driver->id, 'booking_job_id' => $job->id,
                        'action' => $action, 'payload' => $data ?: null, 'created_at' => now()]);
                } catch (QueryException $e) {
                    if (DriverAction::where('client_action_id', mb_substr($clientId, 0, 64))->exists()) {
                        return 'duplicate';
                    }
                    throw $e;
                }
            }
            $job = BookingJob::query()->lockForUpdate()->findOrFail($job->id);
            match ($action) {
                'decline' => self::decline($job, $driver, (string) ($data['reason'] ?? '')),
                'late' => self::late($job, $driver, (int) ($data['minutes'] ?? 0)),
                default => self::step($job, $driver, $action),
            };

            return 'done';
        });
    }

    public static function step(BookingJob $job, User $driver, string $step): void
    {
        if (! $job->isOpen()) {
            throw ValidationException::withMessages(['job' => __('driver.job_closed')]);
        }
        $order = array_keys(BookingJob::DRIVER_STEPS);
        $now = array_search($job->status, $order, true);
        if ($now !== false && array_search($step, $order, true) <= $now) {
            return; // already there (a repeat from the phone)
        }
        DriverJobs::step($job, $step, $driver);
        if ($step === 'on_the_way') {
            $job->forceFill(['started_at' => $job->started_at ?? now()])->save();
            self::tellCustomer($job, 'on_the_way');
        }
        // 6 Nov (limousine L3): the waiting clock starts on arrival; the customer is told.
        if ($step === 'arrived' && $job->type === 'transfer') {
            $job->forceFill(['inspection' => array_merge((array) $job->inspection, ['arrived_at' => now()->toDateTimeString()])])->save();
            self::tellCustomer($job, 'arrived');
        }
        rescue(fn () => AuditLog::note($job, 'driver', $driver->name.': '.BookingJob::DRIVER_STEPS[$step]), report: false);
    }

    public static function canDecline(BookingJob $job): bool
    {
        return (bool) Setting::read('driver_can_decline', true) && in_array($job->status, ['assigned', 'acknowledged'], true);
    }

    public static function decline(BookingJob $job, User $driver, string $reason): void
    {
        if (! self::canDecline($job)) {
            throw ValidationException::withMessages(['job' => __('driver.cannot_decline')]);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('driver.reason_needed')]);
        }
        $job->forceFill(['status' => 'declined', 'status_at' => now(), 'declined_at' => now(), 'decline_reason' => mb_substr($reason, 0, 255)])->save();
        rescue(fn () => AuditLog::note($job, 'driver', $driver->name.' declined the job: '.$reason), report: false);
        $b = $job->booking;
        Notify::team('operate-fleet', 'Driver declined: '.$b?->reference.' · '.$job->typeLabel(),
            $driver->name.': "'.$reason.'" · '.$job->scheduled_at->format('D d M, H:i').'. Give it to another driver.',
            self::officeUrl(), $b?->assignee, 'danger', 'job_declined', 'job_declined:'.$job->id.':'.$job->declined_at->timestamp);
    }

    public static function late(BookingJob $job, User $driver, int $minutes): void
    {
        if (! $job->isOpen() || in_array($job->status, ['waiting'], true)) {
            throw ValidationException::withMessages(['job' => __('driver.job_closed')]);
        }
        if ($minutes < 5 || $minutes > 240) {
            throw ValidationException::withMessages(['minutes' => __('driver.pick_minutes')]);
        }
        $job->forceFill(['late_minutes' => $minutes, 'late_reported_at' => now()])->save();
        rescue(fn () => AuditLog::note($job, 'driver', $driver->name.': running about '.$minutes.' min late'), report: false);
        $b = $job->booking;
        Notify::team('operate-fleet', 'Running late: '.$b?->reference.' · '.$job->typeLabel(),
            $driver->name.' is about '.$minutes.' min late for '.$job->scheduled_at->format('H:i').'.',
            self::officeUrl(), $b?->assignee, 'warning', 'driver_late', 'driver_late:'.$job->id.':'.now()->timestamp);
        self::tellCustomer($job, 'late', $minutes);
    }

    /** "Your driver is on the way" / "about 15 minutes late" (setting; drivers only, never crew on a charter). */
    protected static function tellCustomer(BookingJob $job, string $what, int $minutes = 0): void
    {
        $b = $job->booking;
        if (! Setting::read('driver_updates_to_customer', true) || ! $b?->customer || ($job->type === 'charter' && $job->role !== 'captain')) {
            return;
        }
        if ($what === 'on_the_way' && ! in_array($job->type, ['delivery', 'transfer'], true)) {
            return; // the customer waits for a delivery or a pickup, not for a collection
        }
        $name = strtok((string) $job->driver?->name, ' ') ?: 'Your driver';
        [$title, $body] = match ($what) {
            'late' => ['Your driver is running about '.$minutes.' minutes late', $b->reference.' · '.$job->scheduled_at->format('H:i').'. Sorry for the wait.'],
            'arrived' => ['Your driver has arrived', $name.' is waiting at the pickup point for '.$b->reference.'.'],
            default => ['Your driver is on the way', $name.' is on the way for '.$b->reference.' · '.$job->scheduled_at->format('H:i').'.'],
        };
        // Limousine: the message opens the live tracking page (no login needed).
        $url = $job->type === 'transfer' ? \App\Support\Transfers\Tracking::link($b) : url('/account/bookings/'.$b->id);
        if ($job->type === 'transfer') {
            $body .= ' Follow your driver live: '.$url;
        }
        Notify::customer($b->customer, $title, $body, $url, 'driver_update', $b, 'driver_update:'.$what.':'.$job->id.':'.($what === 'late' ? now()->timestamp : '1'));
    }

    protected static function officeUrl(): ?string
    {
        return rescue(fn () => \App\Filament\Pages\DispatchBoard::getUrl(panel: 'admin'), null, false);
    }

    /** New jobs not accepted in time (setting job_accept_minutes), for crm:notification-reminders. */
    public static function remindUnaccepted(): int
    {
        $minutes = (int) Setting::read('job_accept_minutes');
        if ($minutes <= 0) {
            return 0;
        }
        $n = 0;
        BookingJob::query()->where('status', 'assigned')->whereNotNull('driver_id')
            ->where('assigned_at', '<=', now()->subMinutes($minutes))->where('scheduled_at', '>', now())
            ->with(['booking', 'driver'])->limit(100)->get()
            ->each(function (BookingJob $job) use (&$n) {
                Notify::team('operate-fleet', 'Job not accepted yet: '.$job->booking?->reference, ($job->driver?->name ?? 'The driver').' has not accepted '.$job->typeLabel().' on '.$job->scheduled_at->format('D d M, H:i').'.',
                    self::officeUrl(), $job->booking?->assignee, 'warning', 'job_declined', 'job_unaccepted:'.$job->id.':'.$job->assigned_at?->timestamp);
                $n++;
            });

        return $n;
    }
}
