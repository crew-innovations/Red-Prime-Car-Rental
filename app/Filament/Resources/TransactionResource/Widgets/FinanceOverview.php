<?php

namespace App\Filament\Resources\TransactionResource\Widgets;

use App\Enums\PaymentStatus;
use App\Filament\Resources\TransactionResource;
use App\Models\Transaction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** This month vs last month: revenue, expenses, net profit, money to collect. */
class FinanceOverview extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $now = Transaction::totals(Transaction::query()->betweenDates(now()->startOfMonth(), now()->endOfMonth()));
        $prev = Transaction::totals(Transaction::query()->betweenDates(now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()));
        $all = Transaction::totals(Transaction::query());
        $overdue = Transaction::where('status', PaymentStatus::Overdue)->count();

        // Revenue per day this month for the sparkline.
        $daily = Transaction::query()
            ->betweenDates(now()->startOfMonth(), now()->endOfMonth())
            ->selectRaw('date as day, '.Transaction::receivedSql().' as total')
            ->groupBy('date')
            ->toBase()
            ->pluck('total', 'day');
        $chart = collect(range(1, now()->day))
            ->map(fn (int $d) => (float) ($daily[now()->startOfMonth()->addDays($d - 1)->toDateString()] ?? 0))
            ->all();

        $aed = fn (float $v): string => 'AED '.number_format($v);
        $change = function (float $current, float $previous): array {
            if ($previous <= 0) {
                return [$current > 0 ? 'New this month' : 'No data last month', 'heroicon-m-minus'];
            }
            $pct = round(($current - $previous) / $previous * 100);

            return [($pct >= 0 ? '+' : '').$pct.'% vs last month', $pct >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'];
        };

        [$revDesc, $revIcon] = $change($now['received'], $prev['received']);
        [$expDesc, $expIcon] = $change($now['expenses'], $prev['expenses']);
        $month = now()->format('F');

        return [
            Stat::make("Revenue · {$month}", $aed($now['received']))
                ->icon('heroicon-o-arrow-down-left')
                ->extraAttributes(['class' => 'rp-stat rp-accent-success'])
                ->description($revDesc)
                ->descriptionIcon($revIcon)
                ->chart($chart)
                ->color($now['received'] >= $prev['received'] ? 'success' : 'warning'),
            Stat::make("Expenses · {$month}", $aed($now['expenses']))
                ->icon('heroicon-o-arrow-up-right')
                ->extraAttributes(['class' => 'rp-stat rp-accent-danger'])
                ->description($expDesc)
                ->descriptionIcon($expIcon)
                ->color('gray'),
            Stat::make("Net profit · {$month}", $aed($now['net']))
                ->icon('heroicon-o-scale')
                ->extraAttributes(['class' => 'rp-stat rp-accent-gold'])
                ->description($now['received'] > 0 ? round($now['net'] / $now['received'] * 100).'% margin' : 'Revenue minus expenses')
                ->descriptionIcon('heroicon-m-calculator')
                ->color($now['net'] >= 0 ? 'success' : 'danger'),
            Stat::make('To collect', $aed($all['outstanding']))
                ->icon('heroicon-o-clock')
                ->extraAttributes(['class' => 'rp-stat rp-accent-warning'])
                ->description($overdue ? "{$overdue} overdue payment".($overdue > 1 ? 's' : '') : 'Pending and partly paid')
                ->descriptionIcon($overdue ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-clock')
                ->color($overdue ? 'danger' : 'warning')
                ->url(TransactionResource::getUrl('index', ['activeTab' => 'outstanding'])),
        ];
    }
}
