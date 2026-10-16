<?php

namespace App\Filament\Resources\CarResource\Widgets;

use App\Enums\CarStatus;
use App\Filament\Resources\CarResource;
use App\Models\Car;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FleetOverview extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $byStatus = Car::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $byStatus->sum();
        $available = (int) ($byStatus[CarStatus::Available->value] ?? 0);
        $onRent = (int) ($byStatus[CarStatus::Rented->value] ?? 0) + (int) ($byStatus[CarStatus::Reserved->value] ?? 0);
        $maintenance = (int) ($byStatus[CarStatus::Maintenance->value] ?? 0);
        $partners = Car::where('ownership', 'partner')->count();
        $published = Car::where('is_published', true)->count();
        $docsDue = Car::documentsDueSoon()->count();

        $rates = Car::whereNotNull('daily_price')
            ->selectRaw('avg(daily_price) as avg_rate, min(daily_price) as min_rate, max(daily_price) as max_rate')
            ->first();

        $pct = fn (int $n): string => $total ? round($n / $total * 100).'% of fleet' : 'No cars yet';
        $aed = fn ($v): string => 'AED '.number_format((float) $v);

        return [
            Stat::make('Total fleet', $total)
                ->description(($total - $partners).' own · '.$partners.' partner · '.$published.' on website')
                ->descriptionIcon('heroicon-m-truck')
                ->color('gray'),
            Stat::make('Available now', $available)
                ->description($pct($available))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(CarResource::getUrl('index', ['activeTab' => 'available'])),
            Stat::make('On rent / reserved', $onRent)
                ->description($pct($onRent).' utilisation')
                ->descriptionIcon('heroicon-m-key')
                ->color('info')
                ->url(CarResource::getUrl('index', ['activeTab' => 'rented'])),
            Stat::make('In maintenance', $maintenance)
                ->description($maintenance ? 'Not bookable right now' : 'All cars on the road')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color($maintenance ? 'danger' : 'success')
                ->url(CarResource::getUrl('index', ['activeTab' => 'maintenance'])),
            Stat::make('Average daily rate', $rates?->avg_rate ? $aed($rates->avg_rate) : '-')
                ->description($rates?->min_rate ? $aed($rates->min_rate).' to '.$aed($rates->max_rate) : 'Set prices on your cars')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),
            Stat::make('Documents & service due', $docsDue)
                ->description($docsDue ? 'Within '.Car::EXPIRY_WARNING_DAYS.' days or overdue' : 'Everything up to date')
                ->descriptionIcon($docsDue ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-shield-check')
                ->color($docsDue ? 'danger' : 'success')
                ->url(CarResource::getUrl('index', ['activeTab' => 'documents'])),
        ];
    }
}
