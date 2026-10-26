<?php

namespace App\Filament\Pages;

use App\Filament\Resources\LeadResource\Widgets\LeadOverview;
use App\Filament\Widgets;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Home screen. Today's work first (handovers, returns, fleet, trips),
 * then sales (leads, trend, sources, follow-ups).
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationGroup = 'Overview';

    /** Drivers start on their own jobs instead. */
    public static function shouldRegisterNavigation(): bool
    {
        return ! \App\Support\Access::isDriverOnly();
    }

    public function mount(): void
    {
        if (\App\Support\Access::isDriverOnly()) {
            $this->redirect(MyJobs::getUrl());
        }
    }

    public function getWidgets(): array
    {
        return [
            Widgets\WelcomeBanner::class,
            Widgets\TodayOperations::class,
            Widgets\FleetStatus::class,
            Widgets\UpcomingTrips::class,
            LeadOverview::class,
            Widgets\LeadsTrend::class,
            Widgets\LeadsBySource::class,
            Widgets\MyFollowUps::class,
            Widgets\RecentLeads::class,
        ];
    }

    /** The greeting console at the top already says where you are. */
    public function getHeading(): string
    {
        return '';
    }

    /** Two uneven columns (7 / 5) on wide screens, one column otherwise. */
    public function getColumns(): int|string|array
    {
        return ['default' => 1, 'xl' => 12];
    }
}
