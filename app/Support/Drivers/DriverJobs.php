<?php

namespace App\Support\Drivers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\User;

/**
 * Keeps a car booking's delivery and collection jobs in step with the booking:
 * the booking's driver does both (and drives, on with-driver bookings). Runs
 * after every booking save. Done jobs are never changed; cancelled bookings
 * cancel the open jobs. The driver hears about new, changed and removed jobs.
 */
class DriverJobs
{
    /** Booking statuses that give the driver work. */
    public const ACTIVE = [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed];

    public static function sync(Booking $booking): void
    {
        if ($booking->service_type === \App\Enums\ServiceType::Yacht) {
            self::syncCharter($booking);

            return;
        }
        if ($booking->service_type === \App\Enums\ServiceType::Transfer) {
            self::syncTransfer($booking);

            return;
        }

        $jobs = $booking->jobs()->where('type', '!=', 'charter')->get()->keyBy('type');
        $live = $booking->isCar() && in_array($booking->status, self::ACTIVE, true) && ! $booking->trashed();
        $withDriver = $booking->drive_mode === \App\Enums\DriveMode::WithDriver;

        // 28 Oct: a job only exists when a driver is needed for it. "Collects from us" /
        // "Customer returns it" need no driver; older bookings (no choice saved) keep both jobs.
        $plan = [
            'delivery' => [
                'driver' => $booking->driver_id,
                'needed' => $withDriver || $booking->delivery_method !== 'office',
                'at' => $booking->start_at, 'address' => $booking->pickup_location,
                'done_at' => $booking->delivered_at, 'done_by' => $booking->delivered_by,
            ],
            'collection' => [
                'driver' => $withDriver ? $booking->driver_id : ($booking->return_driver_id ?: $booking->driver_id),
                'needed' => $withDriver || $booking->return_method !== 'office',
                'at' => $booking->end_at, 'address' => $booking->return_location ?: $booking->pickup_location,
                'done_at' => $booking->returned_at, 'done_by' => $booking->received_by,
            ],
        ];

        foreach ($plan as $type => $p) {
            /** @var BookingJob|null $job */
            $job = $jobs[$type] ?? null;
            $wanted = $live && $p['driver'] && $p['needed'];

            if (! $wanted) {
                if ($job && ($job->isOpen() || $job->status === 'declined')) {
                    $job->update(['status' => 'cancelled', 'status_at' => now()]);
                    Notify::driver($job->driver, 'Job cancelled: '.$booking->reference, $job->typeLabel().' on '.$job->scheduled_at->format('D d M, H:i').' is no longer needed.', null, 'gray');
                }

                continue;
            }

            [$at, $address, $doneAt, $doneBy] = [$p['at'], $p['address'], $p['done_at'], $p['done_by']];

            if (! $job) {
                $job = $booking->jobs()->create([
                    'type' => $type,
                    'driver_id' => $p['driver'],
                    'scheduled_at' => $at,
                    'address' => $address,
                    'status' => $doneAt ? 'done' : 'assigned',
                    'status_at' => now(),
                    'assigned_by' => auth()->id(),
                    'assigned_at' => now(),
                    'done_at' => $doneAt,
                    'done_by' => $doneAt ? $doneBy : null,
                ]);
                if (! $doneAt) {
                    self::tellNew($job, $booking);
                }

                continue;
            }

            if ($job->status === 'done') {
                continue; // history stays as it happened
            }

            if ((int) $job->driver_id !== (int) $p['driver']) {
                $old = $job->driver;
                $job->fill([
                    'driver_id' => $p['driver'],
                    'status' => 'assigned',
                    'acknowledged_at' => null,
                    'declined_at' => null, 'decline_reason' => null, 'started_at' => null, 'late_minutes' => null, 'late_reported_at' => null,
                    'assigned_by' => auth()->id(),
                    'assigned_at' => now(),
                    'status_at' => now(),
                    'scheduled_at' => $at,
                    'address' => $address,
                ])->save();
                if ($old) {
                    Notify::driver($old, 'Job moved to another driver: '.$booking->reference, $job->typeLabel().' on '.$job->scheduled_at->format('D d M, H:i').' is no longer yours.', null, 'gray');
                }
                if (! $doneAt) {
                    self::tellNew($job->fresh(), $booking);
                }
            } elseif (! $job->scheduled_at->equalTo($at) || (string) $job->address !== (string) $address || $job->status === 'cancelled') {
                $wasCancelled = $job->status === 'cancelled';
                $job->fill([
                    'scheduled_at' => $at,
                    'address' => $address,
                    'status' => $wasCancelled ? 'assigned' : $job->status,
                    'status_at' => $wasCancelled ? now() : $job->status_at,
                ])->save();
                if (! $doneAt) {
                    Notify::driver($job->driver, ($wasCancelled ? 'Job back on: ' : 'Job changed: ').$booking->reference, $job->typeLabel().' now '.$job->scheduled_at->format('D d M, H:i').($address ? ' · '.$address : ''), self::url($job), 'warning');
                }
            }

            if ($doneAt) {
                $job->update(['status' => 'done', 'status_at' => now(), 'done_at' => $doneAt, 'done_by' => $doneBy]);
            }
        }
    }

    /**
     * Yacht charters: one "charter" job for the captain and one for each crew
     * member, from start to end at the marina. Same rules as car jobs: done
     * jobs stay, removed people are told, a cancelled charter cancels the jobs.
     */
    public static function syncCharter(Booking $booking): void
    {
        $jobs = $booking->jobs()->where('type', 'charter')->get()->keyBy('driver_id');
        $active = in_array($booking->status, self::ACTIVE, true) && ! $booking->trashed() && $booking->crew_mode !== 'bareboat';
        $people = $active ? collect([$booking->captain_id => 'captain'])->filter(fn ($r, $id) => $id)
            ->union(collect((array) $booking->crew_ids)->mapWithKeys(fn ($id) => [(int) $id => 'crew'])) : collect();

        foreach ($jobs as $personId => $job) {
            if (! $people->has($personId) && ($job->isOpen() || $job->status === 'declined')) {
                $job->update(['status' => 'cancelled', 'status_at' => now()]);
                Notify::driver($job->driver, 'Charter cancelled for you: '.$booking->reference, $job->scheduled_at->format('D d M, H:i').' is no longer yours.', null, 'gray');
            }
        }

        foreach ($people as $personId => $role) {
            /** @var BookingJob|null $job */
            $job = $jobs[$personId] ?? null;
            $where = $booking->pickup_location ?: $booking->bookable?->marina;
            if (! $job) {
                $job = $booking->jobs()->create([
                    'type' => 'charter', 'role' => $role, 'driver_id' => $personId,
                    'scheduled_at' => $booking->start_at, 'ends_at' => $booking->end_at, 'address' => $where,
                    'status' => $booking->returned_at ? 'done' : 'assigned', 'status_at' => now(),
                    'assigned_by' => auth()->id(), 'assigned_at' => now(),
                    'done_at' => $booking->returned_at,
                ]);
                if (! $booking->returned_at) {
                    self::tellNew($job, $booking);
                }

                continue;
            }
            if ($job->status === 'done') {
                continue;
            }
            $changed = ! $job->scheduled_at->equalTo($booking->start_at) || ! optional($job->ends_at)->equalTo($booking->end_at)
                || (string) $job->address !== (string) $where || $job->role !== $role || $job->status === 'cancelled';
            if ($changed) {
                $wasCancelled = $job->status === 'cancelled';
                $job->fill(['role' => $role, 'scheduled_at' => $booking->start_at, 'ends_at' => $booking->end_at, 'address' => $where,
                    'status' => $wasCancelled ? 'assigned' : $job->status, 'status_at' => $wasCancelled ? now() : $job->status_at])->save();
                Notify::driver($job->driver, ($wasCancelled ? 'Charter back on: ' : 'Charter changed: ').$booking->reference, $job->scheduled_at->format('D d M, H:i').($where ? ' · '.$where : ''), self::url($job), 'warning');
            }
        }
    }

    /** Limousine / transfer (28 Oct-2): one job for the chauffeur, from pickup to drop-off. */
    public static function syncTransfer(Booking $booking): void
    {
        $jobs = $booking->jobs()->where('type', 'transfer')->get();
        $active = in_array($booking->status, self::ACTIVE, true) && ! $booking->trashed() && $booking->driver_id;
        $where = $booking->pickup_location;

        foreach ($jobs as $job) {
            if ((! $active || (int) $job->driver_id !== (int) $booking->driver_id) && ($job->isOpen() || $job->status === 'declined')) {
                $job->update(['status' => 'cancelled', 'status_at' => now()]);
                Notify::driver($job->driver, 'Transfer cancelled for you: '.$booking->reference, $job->scheduled_at->format('D d M, H:i').' is no longer yours.', null, 'gray');
            }
        }
        if (! $active) {
            return;
        }

        /** @var BookingJob|null $job */
        $job = $jobs->first(fn (BookingJob $j) => (int) $j->driver_id === (int) $booking->driver_id);
        if (! $job) {
            $job = $booking->jobs()->create([
                'type' => 'transfer', 'driver_id' => $booking->driver_id,
                'scheduled_at' => $booking->start_at, 'ends_at' => $booking->end_at, 'address' => $where,
                'status' => $booking->returned_at ? 'done' : 'assigned', 'status_at' => now(),
                'assigned_by' => auth()->id(), 'assigned_at' => now(), 'done_at' => $booking->returned_at,
            ]);
            if (! $booking->returned_at) {
                self::tellNew($job, $booking);
            }

            return;
        }
        if ($job->status === 'done') {
            return;
        }
        $changed = ! $job->scheduled_at->equalTo($booking->start_at) || ! optional($job->ends_at)->equalTo($booking->end_at)
            || (string) $job->address !== (string) $where || $job->status === 'cancelled';
        if ($changed) {
            $wasCancelled = $job->status === 'cancelled';
            $job->fill(['scheduled_at' => $booking->start_at, 'ends_at' => $booking->end_at, 'address' => $where,
                'status' => $wasCancelled ? 'assigned' : $job->status, 'status_at' => $wasCancelled ? now() : $job->status_at])->save();
            Notify::driver($job->driver, ($wasCancelled ? 'Transfer back on: ' : 'Transfer changed: ').$booking->reference, $job->scheduled_at->format('D d M, H:i').($where ? ' · '.$where : ''), self::url($job), 'warning');
        }
    }

    protected static function tellNew(BookingJob $job, Booking $booking): void
    {
        Notify::driver(
            $job->driver,
            'New job: '.$job->typeLabel().' · '.$booking->reference,
            $job->scheduled_at->format('D d M, H:i').' · '.($booking->bookable?->name ?? 'Car').($job->address ? ' · '.$job->address : ''),
            self::url($job),
        );
    }

    public static function url(BookingJob $job): ?string
    {
        try {
            return \App\Filament\Pages\DriverJob::getUrl(['job' => $job->getKey()], panel: 'admin');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Set a driver step (accepted / on the way / arrived) if Business settings allow it. */
    public static function step(BookingJob $job, string $step, User $driver): void
    {
        abort_unless((int) $job->driver_id === (int) $driver->id && $job->isOpen(), 403);
        abort_unless(in_array($step, (array) \App\Models\Setting::read('driver_steps', []), true) && isset(BookingJob::DRIVER_STEPS[$step]), 403);

        $job->update([
            'status' => $step,
            'status_at' => now(),
            'acknowledged_at' => $job->acknowledged_at ?? now(),
        ]);
    }
}
