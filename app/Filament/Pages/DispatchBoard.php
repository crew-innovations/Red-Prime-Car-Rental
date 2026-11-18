<?php

namespace App\Filament\Pages;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\ServiceType;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\DriverUnavailability;
use App\Models\User;
use App\Support\Access;
use App\Support\Drivers\DriverJobs;
use App\Support\Drivers\Notify;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Bookings & drivers › Dispatch board (4 Nov, brief 15): one day, one column per driver, plus the
 * jobs that need a driver (none picked yet, or declined). Drag a job to a driver to give it to
 * them: the booking is changed (driver / collection driver), so every booking check runs, and
 * both drivers are told. Charters are changed on the booking (captain and crew).
 */
class DispatchBoard extends Page
{
    protected static string $view = 'filament.pages.dispatch-board';

    protected static ?string $slug = 'dispatch';

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationGroup = 'Bookings & drivers';

    protected static ?string $navigationLabel = 'Dispatch board';

    protected static ?string $title = 'Dispatch board';

    protected static ?int $navigationSort = 3;

    #[Url]
    public ?string $day = null;

    public static function canAccess(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('dispatch-drivers');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = self::needsDriverQuery(today(), today()->endOfDay())->count()
            + BookingJob::query()->where('status', 'declined')->where('scheduled_at', '>=', now()->startOfDay())->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function mount(): void
    {
        $this->day = rescue(fn () => Carbon::parse($this->day ?? 'today')->toDateString(), today()->toDateString(), false);
    }

    public function getSubheading(): ?string
    {
        return 'Drag a job to a driver to give it to them. Both drivers are told. The booking checks (busy, not available, documents) still apply.';
    }

    public function date(): Carbon
    {
        return Carbon::parse($this->day);
    }

    public function shift(int $days): void
    {
        $this->day = $this->date()->addDays($days)->toDateString();
    }

    public function goToday(): void
    {
        $this->day = today()->toDateString();
    }

    /** Active drivers and crew, with their jobs and not-available time on the day. */
    public function columns(): Collection
    {
        $from = $this->date()->startOfDay();
        $to = $this->date()->endOfDay();
        $jobs = BookingJob::query()->whereBetween('scheduled_at', [$from, $to])->whereNotIn('status', ['cancelled'])
            ->with(['booking.bookable', 'booking.customer', 'booking.vehicleCategory'])->orderBy('scheduled_at')->get();
        $off = DriverUnavailability::query()->where('starts_at', '<=', $to)->where('ends_at', '>=', $from)->get()->groupBy('user_id');
        $last = \App\Models\DriverLocation::query()->where('recorded_at', '>=', now()->subMinutes(15))
            ->selectRaw('user_id, MAX(recorded_at) as at')->groupBy('user_id')->pluck('at', 'user_id');

        return User::role('Driver')->with('driverProfile')->where('is_active', true)->orderBy('name')->get()
            ->map(fn (User $u) => [
                'user' => $u,
                'jobs' => $jobs->where('driver_id', $u->id)->where('status', '!=', 'declined')->values(),
                'off' => $off->get($u->id, collect()),
                'live' => isset($last[$u->id]),
                'does' => $u->driverProfile ? collect(['driver', 'captain', 'crew'])->filter(fn ($w) => $u->driverProfile->does($w))->implode(' · ') : 'driver',
            ]);
    }

    /** Car bookings that need a driver for a delivery / collection and have none; transfers without a chauffeur. */
    protected static function needsDriverQuery(Carbon $from, Carbon $to)
    {
        return Booking::query()->whereIn('status', DriverJobs::ACTIVE)->whereNotIn('status', [BookingStatus::Completed])
            ->where(function ($q) use ($from, $to) {
                $q->where(fn ($w) => $w->where('service_type', ServiceType::Transfer->value)->whereNull('driver_id')->whereBetween('start_at', [$from, $to]))
                    ->orWhere(fn ($w) => $w->where('service_type', ServiceType::Car->value)->whereNull('driver_id')->whereBetween('start_at', [$from, $to])
                        ->where(fn ($m) => $m->where('drive_mode', DriveMode::WithDriver->value)->orWhere('delivery_method', '!=', 'office')))
                    ->orWhere(fn ($w) => $w->where('service_type', ServiceType::Car->value)->whereNull('driver_id')->whereNull('return_driver_id')
                        ->where('drive_mode', '!=', DriveMode::WithDriver->value)->where('return_method', '!=', 'office')->whereNotNull('return_method')
                        ->whereBetween('end_at', [$from, $to]));
            });
    }

    /** Cards for the "Needs a driver" column: declined jobs, then bookings without a driver. */
    public function unassigned(): Collection
    {
        $from = $this->date()->startOfDay();
        $to = $this->date()->endOfDay();
        $declined = BookingJob::query()->where('status', 'declined')->whereBetween('scheduled_at', [$from, $to])
            ->with(['booking.bookable', 'driver'])->orderBy('scheduled_at')->get()
            ->map(fn (BookingJob $j) => ['key' => 'job:'.$j->id, 'at' => $j->scheduled_at, 'type' => $j->type, 'typeLabel' => $j->typeLabel(), 'booking' => $j->booking,
                'address' => $j->address, 'note' => 'Declined by '.($j->driver?->name ?? 'driver').': '.$j->decline_reason, 'movable' => $j->type !== 'charter']);

        $missing = self::needsDriverQuery($from, $to)->with('bookable')->get()->flatMap(function (Booking $b) use ($from, $to) {
            $out = [];
            $with = $b->drive_mode === DriveMode::WithDriver || $b->service_type === ServiceType::Transfer;
            if ($b->start_at->between($from, $to) && ($with || $b->delivery_method !== 'office')) {
                $out[] = ['key' => 'need:'.$b->id.':delivery', 'at' => $b->start_at, 'type' => $b->service_type === ServiceType::Transfer ? 'transfer' : 'delivery',
                    'typeLabel' => $b->service_type === ServiceType::Transfer ? 'Transfer' : ($with ? 'Chauffeur' : 'Delivery'), 'booking' => $b, 'address' => $b->pickup_location, 'note' => 'No driver yet', 'movable' => true];
            }
            if (! $with && $b->end_at->between($from, $to) && $b->return_method && $b->return_method !== 'office' && ! $b->return_driver_id && ! $b->driver_id) {
                $out[] = ['key' => 'need:'.$b->id.':collection', 'at' => $b->end_at, 'type' => 'collection', 'typeLabel' => 'Collection', 'booking' => $b,
                    'address' => $b->return_location ?: $b->pickup_location, 'note' => 'No driver yet', 'movable' => true];
            }

            return $out;
        });

        return $declined->concat($missing)->sortBy('at')->values();
    }

    /** Recent driver activity (accepted, declined, on the way, late, handovers) from the audit log. */
    public function activity(): Collection
    {
        return AuditLog::query()->where('event', 'driver')->latest('id')->limit(12)->get();
    }

    /** Drop: give the job (or the booking's delivery / collection) to this driver. */
    public function move(string $key, int $driverId): void
    {
        abort_unless(static::canAccess(), 403);
        $driver = User::find($driverId);
        if (! $driver || ! $driver->is_active || ! $driver->hasRole('Driver')) {
            Notification::make()->title('Pick an active driver.')->danger()->send();

            return;
        }

        [$kind, $id, $part] = array_pad(explode(':', $key), 3, null);
        $job = $kind === 'job' ? BookingJob::with('booking')->find((int) $id) : null;
        $booking = $job?->booking ?? ($kind === 'need' ? Booking::find((int) $id) : null);
        $part = $job ? $job->type : $part;
        if (! $booking || ! in_array($part, ['delivery', 'collection', 'transfer'], true)) {
            Notification::make()->title('Charters are changed on the booking (captain and crew).')->warning()->send();

            return;
        }
        if ($job && $job->status === 'done') {
            Notification::make()->title('This job is already done.')->warning()->send();

            return;
        }
        abort_unless(Access::scopeOwn(Booking::query(), 'booking')->whereKey($booking->id)->exists(), 403);

        // Same driver who declined: offer it to them again.
        if ($job && $job->status === 'declined' && (int) $job->driver_id === $driver->id) {
            $job->forceFill(['status' => 'assigned', 'status_at' => now(), 'declined_at' => null, 'decline_reason' => null, 'acknowledged_at' => null, 'assigned_by' => auth()->id(), 'assigned_at' => now()])->save();
            Notify::driver($driver, 'Job offered again: '.$booking->reference, $job->typeLabel().' · '.$job->scheduled_at->format('D d M, H:i'), DriverJobs::url($job), 'warning');
            AuditLog::note($booking, 'dispatch', 'Job offered again to '.$driver->name.' ('.$job->typeLabel().')');
            Notification::make()->title('Offered again to '.$driver->name)->success()->send();

            return;
        }

        $old = $part === 'collection' && $booking->drive_mode !== DriveMode::WithDriver ? ($booking->return_driver_id ?: $booking->driver_id) : $booking->driver_id;
        if ((int) $old === $driver->id && ! ($job && $job->status === 'declined')) {
            return; // dropped where it already was
        }
        $changes = match (true) {
            $part === 'collection' && $booking->drive_mode !== DriveMode::WithDriver => ['return_driver_id' => (int) $booking->driver_id === $driver->id ? null : $driver->id],
            $part === 'delivery' && $booking->drive_mode !== DriveMode::WithDriver && $booking->service_type === ServiceType::Car && $booking->driver_id && ! $booking->return_driver_id
                && $booking->return_method !== 'office' => ['driver_id' => $driver->id, 'return_driver_id' => $booking->driver_id], // the collection stays with the old driver
            default => ['driver_id' => $driver->id],
        };

        try {
            $booking->fill($changes)->save(); // the booking's own checks run (busy, not available, documents)
        } catch (ValidationException $e) {
            Notification::make()->title('Not moved')->body(collect($e->errors())->flatten()->implode(' '))->danger()->persistent()->send();

            return;
        }
        AuditLog::note($booking, 'dispatch', ucfirst($part).' given to '.$driver->name.' on the dispatch board');
        Notification::make()->title(ucfirst($part).' of '.$booking->reference.' given to '.$driver->name)->body('Both drivers have been told.')->success()->send();
    }
}
