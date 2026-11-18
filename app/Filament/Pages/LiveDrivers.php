<?php

namespace App\Filament\Pages;

use App\Models\DriverLocation;
use App\Models\Setting;
use App\Support\Access;
use App\Support\Maps\GoogleMaps;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Bookings & drivers › Live drivers map (4 Nov, brief 15). Only drivers on an active job, only when
 * "Share driver location during jobs" is on (H-23). Locations are kept 30 days, then removed.
 */
class LiveDrivers extends Page
{
    protected static string $view = 'filament.pages.live-drivers';

    protected static ?string $slug = 'live-drivers';

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Bookings & drivers';

    protected static ?string $navigationLabel = 'Live drivers map';

    protected static ?string $title = 'Live drivers map';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('track-drivers');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && (bool) Setting::read('driver_location_enabled', false);
    }

    public function enabled(): bool
    {
        return (bool) Setting::read('driver_location_enabled', false);
    }

    public function mapKey(): ?string
    {
        return GoogleMaps::browserKey();
    }

    /** Latest position of each driver in the last 30 minutes, with the job they are on. */
    public function points(): Collection
    {
        $latest = DriverLocation::query()->where('recorded_at', '>=', now()->subMinutes(30))
            ->selectRaw('MAX(id) as id')->groupBy('user_id')->pluck('id');

        return DriverLocation::query()->whereIn('id', $latest)->with(['user', 'job.booking.bookable'])->orderByDesc('recorded_at')->get()
            ->map(fn (DriverLocation $l) => [
                'name' => $l->user?->name,
                'lat' => $l->lat, 'lng' => $l->lng,
                'job' => $l->job ? $l->job->typeLabel().' · '.$l->job->booking?->reference.' · '.$l->job->scheduled_at?->format('H:i') : null,
                'car' => $l->job?->booking?->bookable?->name,
                'status' => $l->job?->statusLabel(),
                'at' => $l->recorded_at->diffForHumans(),
                'old' => $l->recorded_at->lt(now()->subMinutes(5)),
                'accuracy' => $l->accuracy,
                'link' => 'https://www.google.com/maps/search/?api=1&query='.$l->lat.','.$l->lng,
            ]);
    }
}
