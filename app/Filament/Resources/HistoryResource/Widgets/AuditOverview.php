<?php

namespace App\Filament\Resources\HistoryResource\Widgets;

use App\Models\AuditLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** 19 Nov: today at a glance on the Audit log (who is busy, sign-ins, deletions, failed sign-ins). */
class AuditOverview extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $today = AuditLog::query()->where('created_at', '>=', today());
        $busiest = (clone $today)->whereNotNull('user_id')->whereNotIn('event', ['login', 'logout'])
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->orderByDesc('n')->limit(3)->with('user:id,name')->get();
        $week = AuditLog::query()->where('created_at', '>=', today()->subDays(6));

        return [
            Stat::make('Actions today', (clone $today)->whereNotIn('event', ['login', 'logout'])->count())
                ->description($busiest->isEmpty() ? 'Nobody yet' : 'Most: '.$busiest->map(fn ($r) => ($r->user?->name ?? '#'.$r->user_id).' ('.$r->n.')')->implode(', '))
                ->icon('heroicon-o-bolt'),
            Stat::make('Signed in today', (clone $today)->where('event', 'login')->distinct()->count('user_id'))
                ->description('people')
                ->icon('heroicon-o-arrow-right-on-rectangle'),
            Stat::make('Deleted (7 days)', (clone $week)->where('event', 'deleted')->count())
                ->icon('heroicon-o-trash')
                ->color('danger'),
            Stat::make('Failed sign-ins (7 days)', (clone $week)->where('event', 'login_failed')->count())
                ->icon('heroicon-o-shield-exclamation')
                ->color('warning'),
        ];
    }
}
