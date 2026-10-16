<?php

namespace App\Filament\Widgets;

use App\Enums\CarStatus;
use App\Enums\TripStatus;
use App\Enums\YachtStatus;
use App\Filament\Resources\CarResource;
use App\Filament\Resources\TripResource;
use App\Filament\Resources\YachtResource;
use App\Models\Car;
use App\Models\Trip;
use App\Models\Yacht;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FleetSnapshot extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Fleet today';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $cars = Car::count();
        $carsFree = Car::where('status', CarStatus::Available)->count();
        $yachts = Yacht::count();
        $yachtsFree = Yacht::where('status', YachtStatus::Available)->count();
        $trips = Trip::where('status', TripStatus::Active)->count();
        $tripsDated = Trip::where('status', TripStatus::Active)->has('upcomingDates')->count();
        $docs = Car::documentsDueSoon()->count() + Yacht::documentsDueSoon()->count();

        return [
            Stat::make('Cars available', "{$carsFree} / {$cars}")
                ->description(Car::where('status', CarStatus::Rented)->count().' on rent now')
                ->descriptionIcon('heroicon-m-truck')
                ->color('success')
                ->url(CarResource::getUrl('index')),
            Stat::make('Yachts available', "{$yachtsFree} / {$yachts}")
                ->description(Yacht::where('status', YachtStatus::Booked)->count().' booked')
                ->descriptionIcon('heroicon-m-lifebuoy')
                ->color('info')
                ->url(YachtResource::getUrl('index')),
            Stat::make('Active yacht trips', $trips)
                ->description($trips - $tripsDated ? ($trips - $tripsDated).' without upcoming dates' : 'All have upcoming dates')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($trips - $tripsDated ? 'warning' : 'success')
                ->url(TripResource::getUrl('index')),
            Stat::make('Documents & service due', $docs)
                ->description($docs ? 'Cars and yachts, next 30 days' : 'Everything up to date')
                ->descriptionIcon($docs ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-shield-check')
                ->color($docs ? 'danger' : 'success'),
        ];
    }
}
