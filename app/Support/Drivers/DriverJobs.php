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
        $jobs = $booking->jobs()->get()->keyBy('type');
        $active = $booking->isCar()
            && $booking->driver_id
            && in_array($booking->status, self::ACTIVE, true)
            && ! $booking->trashed();

        if (! $active) {
            foreach ($jobs as $job) {
                if ($job->isOpen()) {
                    $job->update(['status' => 'cancelled', 'status_at' => now()]);
                    Notify::driver($job->driver, 'Job cancelled: '.$booking->reference, $job->typeLabel().' on '.$job->scheduled_at->format('D d M, H:i').' is no longer needed.', null, 'gray');
                }
            }

            return;
        }

        $plan = [
            'delivery' => [$booking->start_at, $booking->pickup_location, $booking->delivered_at, $booking->delivered_by],
            'collection' => [$booking->end_at, $booking->return_location ?: $booking->pickup_location, $booking->returned_at, $booking->received_by],
        ];

        foreach ($plan as $type => [$at, $address, $doneAt, $doneBy]) {
            /** @var BookingJob|null $job */
            $job = $jobs[$type] ?? null;

            if (! $job) {
                $job = $booking->jobs()->create([
                    'type' => $type,
                    'driver_id' => $booking->driver_id,
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

            if ((int) $job->driver_id !== (int) $booking->driver_id) {
                $old = $job->driver;
                $job->fill([
                    'driver_id' => $booking->driver_id,
                    'status' => 'assigned',
                    'acknowledged_at' => null,
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
