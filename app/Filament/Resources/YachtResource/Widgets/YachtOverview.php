<?php

namespace App\Filament\Resources\YachtResource\Widgets;

use App\Enums\YachtStatus;
use App\Filament\Resources\YachtResource;
use App\Models\Yacht;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class YachtOverview extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $total = Yacht::count();
        $available = Yacht::where('status', YachtStatus::Available)->count();
        $booked = Yacht::where('status', YachtStatus::Booked)->count();
        $guests = (int) Yacht::sum('max_guests');
        $docsDue = Yacht::documentsDueSoon()->count();
        $rates = Yacht::whereNotNull('hourly_price')
            ->selectRaw('avg(hourly_price) as avg_rate, min(hourly_price) as min_rate, max(hourly_price) as max_rate')
            ->first();
        $aed = fn ($v): string => 'AED '.number_format((float) $v);

        return [
            Stat::make('Total yachts', $total)
                ->description($guests.' guests total capacity')
                ->descriptionIcon('heroicon-m-users')
                ->color('gray'),
            Stat::make('Available now', $available)
                ->description($booked.' booked')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(YachtResource::getUrl('index', ['activeTab' => 'available'])),
            Stat::make('Average hourly rate', $rates?->avg_rate ? $aed($rates->avg_rate) : '-')
                ->description($rates?->min_rate ? $aed($rates->min_rate).' to '.$aed($rates->max_rate) : 'Set prices on your yachts')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),
            Stat::make('Documents & service due', $docsDue)
                ->description($docsDue ? 'Within '.Yacht::EXPIRY_WARNING_DAYS.' days or overdue' : 'Everything up to date')
                ->descriptionIcon($docsDue ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-shield-check')
                ->color($docsDue ? 'danger' : 'success')
                ->url(YachtResource::getUrl('index', ['activeTab' => 'documents'])),
        ];
    }
}
