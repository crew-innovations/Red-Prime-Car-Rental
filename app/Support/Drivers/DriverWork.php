<?php

namespace App\Support\Drivers;

use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\BookingResource\BookingActions;
use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\Car;
use App\Models\DamageReport;
use App\Models\Setting;
use App\Models\User;
use App\Support\BookingFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a driver can do on their job: hand the car over (with the inspection),
 * check it on collection and report damage. No money is ever recorded here;
 * prices, payments, deposits, refunds and charges stay with the office.
 * Every check is done on the server, whatever the screen sent.
 */
class DriverWork
{
    public static function ownJob(BookingJob $job, User $driver): void
    {
        abort_unless((int) $job->driver_id === (int) $driver->id, 404);
    }

    public static function canHandOver(BookingJob $job): bool
    {
        return $job->type === 'delivery'
            && $job->isOpen()
            && $job->status !== 'waiting'
            && Setting::read('driver_can_handover', true)
            && in_array($job->booking?->status, [BookingStatus::Confirmed, BookingStatus::PendingPayment], true);
    }

    public static function canCollect(BookingJob $job): bool
    {
        return $job->type === 'collection'
            && $job->isOpen()
            && Setting::read('driver_can_return', true)
            && $job->booking?->status === BookingStatus::InProgress;
    }

    /** The office approves the driver's handover check: now the car is released. */
    public static function approveHandover(BookingJob $job, User $staff): void
    {
        abort_unless($job->type === 'delivery' && $job->status === 'waiting', 404);
        $booking = $job->booking;
        if ($blockers = BookingActions::handoverBlockers($booking)) {
            throw ValidationException::withMessages(['data' => implode(' ', $blockers)]);
        }
        $check = (array) $job->inspection;

        DB::transaction(function () use ($job, $booking, $check, $staff) {
            $fresh = Booking::whereKey($booking->id)->lockForUpdate()->first();
            if (! in_array($fresh->status, [BookingStatus::Confirmed, BookingStatus::PendingPayment], true)) {
                throw ValidationException::withMessages(['data' => 'This booking was already handed over or changed.']);
            }
            BookingFlow::deliver($fresh, [
                'delivered_at' => isset($check['at']) ? \Illuminate\Support\Carbon::parse($check['at']) : now(),
                'delivery_km' => $check['km'] ?? null,
                'delivery_fuel' => $check['fuel'] ?? null,
                'delivery_notes' => $check['notes_text'] ?? null,
            ]);
            $fresh->forceFill(['delivered_by' => $job->driver_id])->saveQuietly();
            $job->refresh()->update([
                'status' => 'done', 'status_at' => now(), 'done_at' => now(), 'done_by' => $job->driver_id,
                'approved_at' => now(), 'approved_by' => $staff->id,
            ]);
        });

        Notify::driver($job->driver, 'OK to hand over: '.$booking->reference, 'The office approved your check. You can give the keys now.', DriverJobs::url($job), 'success');
    }

    public static function rejectHandover(BookingJob $job, User $staff, string $reason): void
    {
        abort_unless($job->type === 'delivery' && $job->status === 'waiting', 404);
        $job->update([
            'status' => 'arrived',
            'status_at' => now(),
            'inspection' => array_merge((array) $job->inspection, ['rejected' => $reason, 'rejected_by' => $staff->name]),
        ]);
        Notify::driver($job->driver, 'Handover not approved yet: '.$job->booking?->reference, $reason.' Please do the check again or call the office.', DriverJobs::url($job), 'danger');
    }

    /** Captain starts the charter (guests on board): the booking becomes In progress. */
    public static function canStartCharter(BookingJob $job): bool
    {
        return $job->type === 'charter' && $job->role === 'captain' && $job->isOpen()
            && in_array($job->booking?->status, [BookingStatus::Confirmed, BookingStatus::PendingPayment], true);
    }

    /** Captain ends the charter; the office then closes it (extra charges, payment, deposit). */
    public static function canFinishCharter(BookingJob $job): bool
    {
        return $job->type === 'charter' && $job->role === 'captain' && $job->isOpen()
            && $job->booking?->status === BookingStatus::InProgress;
    }

    public static function startCharter(BookingJob $job, User $captain, array $data): void
    {
        self::ownJob($job, $captain);
        if (! self::canStartCharter($job)) {
            throw ValidationException::withMessages(['data' => 'This charter can no longer be started from here. Please call the office.']);
        }
        $booking = $job->booking;
        if ($blockers = BookingActions::handoverBlockers($booking)) {
            throw ValidationException::withMessages(['data' => 'The office needs to sort this first: '.implode(' ', $blockers)]);
        }
        $guests = isset($data['guests']) && $data['guests'] !== '' ? (int) $data['guests'] : null;
        if ($guests !== null && $booking->bookable?->max_guests && $guests > $booking->bookable->max_guests) {
            throw ValidationException::withMessages(['data.guests' => 'More guests than this yacht may take ('.$booking->bookable->max_guests.'). Call the office before leaving.']);
        }

        DB::transaction(function () use ($job, $booking, $data, $guests, $captain) {
            $fresh = Booking::whereKey($booking->id)->lockForUpdate()->first();
            if (! in_array($fresh->status, [BookingStatus::Confirmed, BookingStatus::PendingPayment], true)) {
                throw ValidationException::withMessages(['data' => 'This charter was already started or changed by the office.']);
            }
            // No money here: payments, deposits and charges stay with the office.
            BookingFlow::deliver($fresh, [
                'delivered_at' => now(),
                'delivery_notes' => trim('Charter started by '.$captain->name.($guests !== null ? ' · '.$guests.' guests on board' : '').(filled($data['notes'] ?? null) ? "\n".$data['notes'] : '')),
            ]);
            $job->refresh()->update([
                'status' => 'arrived',
                'status_at' => now(),
                'acknowledged_at' => $job->acknowledged_at ?? now(),
                'inspection' => array_filter(['started_at' => now()->toDateTimeString(), 'guests' => $guests, 'start_notes' => $data['notes'] ?? null], fn ($v) => $v !== null && $v !== ''),
            ]);
        });

        Notify::staff('operate-fleet', 'Charter started: '.$booking->reference, ($booking->bookable?->name ?? 'Yacht').' · captain '.$captain->name,
            BookingResource::getUrl('edit', ['record' => $booking]), $booking->assignee, 'success');
    }

    public static function finishCharter(BookingJob $job, User $captain, array $data): void
    {
        self::ownJob($job, $captain);
        if (! self::canFinishCharter($job)) {
            throw ValidationException::withMessages(['data' => 'This charter can no longer be finished from here. Please call the office.']);
        }
        $booking = $job->booking;
        $report = null;

        DB::transaction(function () use ($job, $booking, $data, $captain, &$report) {
            $job = BookingJob::whereKey($job->id)->lockForUpdate()->first();
            if (! $job->isOpen()) {
                throw ValidationException::withMessages(['data' => 'This charter was already finished.']);
            }
            if (filled($data['damage'] ?? null)) {
                $report = DamageReport::create(['booking_id' => $booking->id, 'booking_job_id' => $job->id, 'reported_by' => $captain->id,
                    'stage' => 'collection', 'description' => $data['damage'], 'status' => 'open']);
            }
            $job->update([
                'status' => 'done', 'status_at' => now(), 'done_at' => now(), 'done_by' => $captain->id,
                'inspection' => array_merge((array) $job->inspection, array_filter(['ended_at' => now()->toDateTimeString(), 'end_notes' => $data['notes'] ?? null, 'damage' => $data['damage'] ?? null])),
            ]);
            // The crew's charter jobs end with the captain's.
            $booking->jobs()->where('type', 'charter')->whereKeyNot($job->id)->open()->update(['status' => 'done', 'status_at' => now(), 'done_at' => now(), 'done_by' => $captain->id]);
        });

        Notify::staff('operate-fleet', 'Charter finished: '.$booking->reference, 'Captain '.$captain->name.'. Please close it (extra charges, payment, deposit).'.($report ? ' Damage was reported.' : ''),
            BookingResource::getUrl('edit', ['record' => $booking]), $booking->assignee, $report ? 'danger' : 'warning');
        if ($report) {
            self::tellDamage($report);
        }
    }

    /** Hand the car over. Returns nothing; throws ValidationException with a plain message when something is missing. */
    public static function handOver(BookingJob $job, User $driver, array $data): void
    {
        self::ownJob($job, $driver);
        if (! self::canHandOver($job)) {
            throw ValidationException::withMessages(['data' => 'This handover can no longer be done from here. Please call the office.']);
        }

        $booking = $job->booking;
        $checked = self::checkInspection($data, 'handover', $booking->bookable instanceof Car ? (int) $booking->bookable->mileage : 0);

        if ($blockers = BookingActions::handoverBlockers($booking)) {
            throw ValidationException::withMessages(['data' => 'The office needs to sort this first: '.implode(' ', $blockers)]);
        }

        // Business settings may ask for the office's OK first: keep the check, wait for approval.
        if (Setting::read('handover_requires_approval')) {
            DB::transaction(function () use ($job, $booking, $checked) {
                foreach ($checked['photos'] as $path) {
                    $booking->addMediaFromDisk($path, 'private')->toMediaCollection('delivery');
                }
                $job->refresh()->update([
                    'inspection' => self::stored($checked) + ['notes_text' => self::notesText($checked)],
                    'status' => 'waiting',
                    'status_at' => now(),
                ]);
            });
            Notify::staff('operate-fleet', 'Approve handover: '.$booking->reference, $driver->name.' sent the handover check for '.($booking->bookable?->name ?? 'the car').'. The car is not released until you approve.',
                BookingResource::getUrl('edit', ['record' => $booking]), $booking->assignee, 'warning');

            return;
        }

        DB::transaction(function () use ($job, $booking, $checked, $driver) {
            $fresh = Booking::whereKey($booking->id)->lockForUpdate()->first();
            if (! in_array($fresh->status, [BookingStatus::Confirmed, BookingStatus::PendingPayment], true)) {
                throw ValidationException::withMessages(['data' => 'This booking was already handed over or changed by the office.']);
            }

            // Money fields are deliberately not passed: drivers never record payments or deposits.
            BookingFlow::deliver($fresh, [
                'delivered_at' => now(),
                'delivery_km' => $checked['km'],
                'delivery_fuel' => $checked['fuel'],
                'delivery_notes' => self::notesText($checked),
                'photos' => $checked['photos'],
            ]);

            $job->refresh()->update([
                'inspection' => self::stored($checked),
                'status' => 'done',
                'status_at' => now(),
                'done_at' => now(),
                'done_by' => $driver->id,
            ]);
        });

        $booking->refresh();
        Notify::staff('operate-fleet', 'Handed over by '.$driver->name.': '.$booking->reference,
            ($booking->bookable?->name ?? 'Car').' · '.$booking->customer?->name,
            BookingResource::getUrl('edit', ['record' => $booking]), $booking->assignee, 'success');
    }

    /** Check the car on collection. The office then completes the return (charges, payment, deposit). */
    public static function collect(BookingJob $job, User $driver, array $data): void
    {
        self::ownJob($job, $driver);
        if (! self::canCollect($job)) {
            throw ValidationException::withMessages(['data' => 'This collection can no longer be done from here. Please call the office.']);
        }

        $booking = $job->booking;
        $checked = self::checkInspection($data, 'return', (int) ($booking->delivery_km ?? 0));
        if (in_array($checked['condition'], ['minor', 'damaged'], true) && blank($checked['damage'])) {
            throw ValidationException::withMessages(['data.damage' => 'Describe the damage you found.']);
        }

        $report = null;
        DB::transaction(function () use ($job, $booking, $checked, $driver, &$report) {
            $job = BookingJob::whereKey($job->id)->lockForUpdate()->first();
            if (! $job->isOpen()) {
                throw ValidationException::withMessages(['data' => 'This collection was already recorded.']);
            }

            foreach ($checked['photos'] as $path) {
                $booking->addMediaFromDisk($path, 'private')->toMediaCollection('return');
            }

            if (filled($checked['damage'])) {
                $report = DamageReport::create([
                    'booking_id' => $booking->id,
                    'booking_job_id' => $job->id,
                    'reported_by' => $driver->id,
                    'stage' => 'collection',
                    'description' => $checked['damage'],
                    'status' => 'open',
                ]);
            }

            $job->update([
                'inspection' => self::stored($checked),
                'status' => 'done',
                'status_at' => now(),
                'done_at' => now(),
                'done_by' => $driver->id,
            ]);
        });

        Notify::staff('operate-fleet', 'Car collected by '.$driver->name.': '.$booking->reference,
            'Please complete the return (charges, payment, deposit).'.($report ? ' Damage was reported.' : ''),
            BookingResource::getUrl('edit', ['record' => $booking]), $booking->assignee, $report ? 'danger' : 'warning');
        if ($report) {
            self::tellDamage($report);
        }
    }

    public static function reportDamage(BookingJob $job, User $driver, string $description, array $photos): DamageReport
    {
        self::ownJob($job, $driver);
        abort_unless(Setting::read('driver_can_report_damage', true), 403);
        if (trim($description) === '') {
            throw ValidationException::withMessages(['data.description' => 'Describe what you found.']);
        }

        $report = DB::transaction(function () use ($job, $driver, $description, $photos) {
            $report = DamageReport::create([
                'booking_id' => $job->booking_id,
                'booking_job_id' => $job->id,
                'reported_by' => $driver->id,
                'stage' => $job->type,
                'description' => mb_substr(trim($description), 0, 2000),
                'status' => 'open',
            ]);
            foreach (\App\Support\PrivateFiles::freshUploads(array_filter($photos), 'tmp') as $path) {
                $report->addMediaFromDisk($path, 'private')->toMediaCollection('photos');
            }

            return $report;
        });

        self::tellDamage($report);

        return $report;
    }

    protected static function tellDamage(DamageReport $report): void
    {
        Notify::staff('review-damage', 'Damage reported on '.$report->booking?->reference,
            \Illuminate\Support\Str::limit($report->description, 140),
            \App\Filament\Resources\DamageReportResource::getUrl('index'), $report->booking?->assignee, 'danger');
    }

    /** Required fields and photos from Business settings; returns the clean values. */
    protected static function checkInspection(array $data, string $stage, int $minKm): array
    {
        $errors = [];
        $km = isset($data['km']) && $data['km'] !== '' ? (int) $data['km'] : null;
        $fuel = $data['fuel'] ?? null;
        $photos = \App\Support\PrivateFiles::freshUploads(array_values(array_filter((array) ($data['photos'] ?? []))), 'tmp');
        $list = (array) Setting::read($stage.'_checklist', []);
        $ticked = array_values(array_intersect((array) ($data['checklist'] ?? []), $list));

        if (Setting::read($stage.'_require_km', true) && $km === null) {
            $errors['data.km'] = 'Enter the odometer reading.';
        }
        if ($km !== null && ($km < 0 || ($minKm && $km < $minKm))) {
            $errors['data.km'] = 'The odometer cannot be lower than '.number_format($minKm).' km.';
        }
        if (Setting::read($stage.'_require_fuel', true) && blank($fuel)) {
            $errors['data.fuel'] = 'Pick the fuel level.';
        }
        if ($fuel !== null && $fuel !== '' && ! isset(Booking::FUEL_LEVELS[$fuel])) {
            $errors['data.fuel'] = 'Pick the fuel level.';
        }
        $min = max(0, (int) Setting::read($stage.'_min_photos', 0));
        if (count($photos) < $min) {
            $errors['data.photos'] = "Add at least {$min} photo(s).";
        }
        if (Setting::read('checklist_all_required') && count($ticked) < count($list)) {
            $errors['data.checklist'] = 'Tick every item on the checklist.';
        }
        $condition = $data['condition'] ?? 'good';
        if ($stage === 'return' && ! isset(Booking::CONDITIONS[$condition])) {
            $errors['data.condition'] = 'Pick the condition.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'km' => $km,
            'fuel' => filled($fuel) ? $fuel : null,
            'photos' => $photos,
            'checklist' => $ticked,
            'checklist_total' => count($list),
            'condition' => $stage === 'return' ? $condition : null,
            'damage' => trim((string) ($data['damage'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
        ];
    }

    protected static function stored(array $checked): array
    {
        return [
            'at' => now()->toDateTimeString(),
            'km' => $checked['km'],
            'fuel' => $checked['fuel'],
            'condition' => $checked['condition'],
            'checklist' => $checked['checklist'],
            'photos' => count($checked['photos']),
            'damage' => $checked['damage'] ?: null,
            'notes' => $checked['notes'] ?: null,
        ];
    }

    protected static function notesText(array $checked): ?string
    {
        $parts = array_filter([
            $checked['notes'] ?: null,
            $checked['checklist_total'] ? 'Checklist: '.count($checked['checklist']).'/'.$checked['checklist_total'].' ticked' : null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }
}
