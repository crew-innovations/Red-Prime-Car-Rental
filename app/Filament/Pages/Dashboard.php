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

    /** 6 Nov (S4 / C.4): each role sees its own home; the other widgets stay in the code. */
    public function getWidgets(): array
    {
        return match (\App\Support\Menu::roleFor(auth()->user())) {
            'Agent' => [Widgets\WelcomeBanner::class, Widgets\RoleHome::class, Widgets\MyWork::class, Widgets\MyFollowUps::class, Widgets\RecentLeads::class],
            'Accounts' => [Widgets\WelcomeBanner::class, Widgets\RoleHome::class, Widgets\BusinessBoard::class],
            default => [Widgets\WelcomeBanner::class, Widgets\RoleHome::class, Widgets\TodayOperations::class, Widgets\FleetStatus::class, Widgets\BusinessBoard::class, LeadOverview::class, Widgets\LeadsTrend::class],
        };
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
