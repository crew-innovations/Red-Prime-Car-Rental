<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Filament\Resources\TripResource;
use App\Models\TripDate;
use Filament\Widgets\Widget;

class UpcomingTrips extends Widget
{
    public static function canView(): bool
    {
        return ! \App\Support\Access::isDriverOnly();
    }

    protected static string $view = 'filament.widgets.upcoming-trips';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $dates = TripDate::query()
            ->with('trip')
            ->whereHas('trip', fn ($q) => $q->where('status', TripStatus::Active))
            ->whereBetween('date', [today(), today()->addDays(7)])
            ->where('is_available', true)
            ->orderBy('date')
            ->orderBy('start_time')
            ->limit(6)
            ->get()
            ->map(fn (TripDate $d) => [
                'sort' => $d->date->toDateString().' '.($d->start_time ?? '00:00'),
                'day' => $d->date->format('j'),
                'month' => $d->date->format('M'),
                'today' => $d->date->isToday(),
                'name' => $d->trip->name,
                'when' => $d->date->isToday() ? 'Today' : ($d->date->isTomorrow() ? 'Tomorrow' : $d->date->format('l')),
                'time' => $d->start_time ? substr($d->start_time, 0, 5) : null,
                'seats' => $d->available_slots,
                'url' => TripResource::getUrl('edit', ['record' => $d->trip_id]),
            ]);

        // Trips & Packages departures in the same week, from their own schedule.
        $departures = \App\Models\PackageDeparture::query()
            ->with('package')
            ->whereHas('package', fn ($q) => $q->where('status', TripStatus::Active))
            ->whereBetween('start_date', [today(), today()->addDays(7)])
            ->where('is_available', true)
            ->orderBy('start_date')
            ->limit(6)
            ->get()
            ->map(fn (\App\Models\PackageDeparture $d) => [
                'sort' => $d->start_date->toDateString().' '.($d->start_time ?? '00:00'),
                'day' => $d->start_date->format('j'),
                'month' => $d->start_date->format('M'),
                'today' => $d->start_date->isToday(),
                'name' => $d->package?->name,
                'kind' => 'Package',
                'when' => $d->start_date->isToday() ? 'Today' : ($d->start_date->isTomorrow() ? 'Tomorrow' : $d->start_date->format('l')),
                'time' => $d->start_time ? substr($d->start_time, 0, 5) : null,
                'seats' => $d->seats() === null ? null : max(0, $d->seats() - $d->bookedGuests()),
                'url' => \App\Filament\Resources\PackageResource::getUrl('edit', ['record' => $d->package_id]),
            ]);

        $dates = $dates->map(fn ($d) => $d + ['kind' => 'Yacht trip'])->concat($departures)->sortBy('sort')->take(8)->values();

        return ['dates' => $dates, 'allUrl' => TripResource::getUrl('index')];
    }
}
