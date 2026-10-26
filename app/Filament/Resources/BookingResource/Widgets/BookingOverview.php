<?php

namespace App\Filament\Resources\BookingResource\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BookingOverview extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $out = \App\Support\Access::scopeOwn(\App\Models\Booking::query(), 'booking')->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])->whereDate('start_at', today())->count();
        $back = \App\Support\Access::scopeOwn(\App\Models\Booking::query(), 'booking')->where('status', BookingStatus::InProgress)->whereDate('end_at', today())->count();
        $late = \App\Support\Access::scopeOwn(\App\Models\Booking::query(), 'booking')->where('status', BookingStatus::InProgress)->where('end_at', '<', now())->count();
        $onRent = \App\Support\Access::scopeOwn(\App\Models\Booking::query(), 'booking')->where('status', BookingStatus::InProgress)->count();
        $week = \App\Support\Access::scopeOwn(\App\Models\Booking::query(), 'booking')->whereIn('status', BookingStatus::blocking())->whereBetween('start_at', [now(), now()->addDays(7)->endOfDay()])->count();
        $balance = (float) \App\Support\Access::scopeOwn(\App\Models\Booking::query(), 'booking')->whereIn('status', BookingStatus::billable())->sum('balance');

        return [
            Stat::make('Going out today', $out)
                ->icon('heroicon-o-arrow-up-right')
                ->extraAttributes(['class' => 'rp-stat rp-accent-primary'])
                ->description($week.' more booked in the next 7 days')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('primary')
                ->url(BookingResource::getUrl('index', ['activeTab' => 'today'])),
            Stat::make('Coming back today', $back)
                ->icon('heroicon-o-arrow-uturn-left')
                ->extraAttributes(['class' => 'rp-stat rp-accent-warning'])
                ->description($late ? "{$late} overdue return".($late > 1 ? 's' : '') : 'No late returns')
                ->descriptionIcon($late ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($late ? 'danger' : 'success')
                ->url(BookingResource::getUrl('index', ['activeTab' => 'today'])),
            Stat::make('With customers now', $onRent)
                ->icon('heroicon-o-key')
                ->extraAttributes(['class' => 'rp-stat rp-accent-info'])
                ->description('Cars and yachts out')
                ->descriptionIcon('heroicon-m-truck')
                ->color('info')
                ->url(BookingResource::getUrl('index', ['activeTab' => 'out'])),
            Stat::make('Balance to collect', 'AED '.number_format($balance))
                ->icon('heroicon-o-banknotes')
                ->extraAttributes(['class' => 'rp-stat rp-accent-gold'])
                ->description('Across open and completed bookings')
                ->descriptionIcon('heroicon-m-clock')
                ->color($balance > 0 ? 'warning' : 'success')
                ->url(BookingResource::getUrl('index', ['activeTab' => 'balance'])),
        ];
    }
}
