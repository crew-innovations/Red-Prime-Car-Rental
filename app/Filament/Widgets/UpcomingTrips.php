<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Filament\Resources\TripResource;
use App\Models\TripDate;
use Filament\Widgets\Widget;

class UpcomingTrips extends Widget
{
    protected static string $view = 'filament.widgets.upcoming-trips';

    protected static ?int $sort = 6;

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
                'day' => $d->date->format('j'),
                'month' => $d->date->format('M'),
                'today' => $d->date->isToday(),
                'name' => $d->trip->name,
                'when' => $d->date->isToday() ? 'Today' : ($d->date->isTomorrow() ? 'Tomorrow' : $d->date->format('l')),
                'time' => $d->start_time ? substr($d->start_time, 0, 5) : null,
                'seats' => $d->available_slots,
                'url' => TripResource::getUrl('edit', ['record' => $d->trip_id]),
            ]);

        return ['dates' => $dates, 'allUrl' => TripResource::getUrl('index')];
    }
}
