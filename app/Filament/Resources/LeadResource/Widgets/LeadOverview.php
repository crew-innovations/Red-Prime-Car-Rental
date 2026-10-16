<?php

namespace App\Filament\Resources\LeadResource\Widgets;

use App\Enums\LeadStatus;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LeadOverview extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $since = now()->subDays(30);

        $today = Lead::whereDate('created_at', today())->count();
        $yesterday = Lead::whereDate('created_at', today()->subDay())->count();
        $open = Lead::open()->count();
        $due = Lead::followUpDue()->count();

        $last30 = Lead::where('created_at', '>=', $since);
        $total30 = (clone $last30)->count();
        $converted30 = (clone $last30)->where('status', LeadStatus::Converted)->count();
        $rate = $total30 ? round($converted30 / $total30 * 100) : 0;

        $topSource = Lead::where('leads.created_at', '>=', $since)
            ->join('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->selectRaw('lead_sources.name, count(*) as total')
            ->groupBy('lead_sources.name')
            ->orderByDesc('total')
            ->first();

        // Leads per day for the last 14 days, for the sparkline.
        $daily = Lead::where('created_at', '>=', today()->subDays(13))
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');
        $chart = collect(range(13, 0))
            ->map(fn (int $i) => (int) ($daily[today()->subDays($i)->toDateString()] ?? 0))
            ->all();

        return [
            Stat::make('New leads today', $today)
                ->description($yesterday.' yesterday')
                ->descriptionIcon($today >= $yesterday ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->chart($chart)
                ->color($today >= $yesterday ? 'success' : 'warning'),
            Stat::make('Open leads', $open)
                ->description('Not yet converted or lost')
                ->descriptionIcon('heroicon-m-inbox')
                ->color('info')
                ->url(LeadResource::getUrl('index', ['activeTab' => 'open'])),
            Stat::make('Follow-ups due', $due)
                ->description($due ? 'Call or message them now' : 'Nothing overdue')
                ->descriptionIcon($due ? 'heroicon-m-bell-alert' : 'heroicon-m-check-circle')
                ->color($due ? 'danger' : 'success')
                ->url(LeadResource::getUrl('index', ['activeTab' => 'follow_up'])),
            Stat::make('Conversion (30 days)', $rate.'%')
                ->description($topSource ? "{$converted30} of {$total30} · top source: {$topSource->name}" : "{$converted30} of {$total30} leads")
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('warning'),
        ];
    }
}
