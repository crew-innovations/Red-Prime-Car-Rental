<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Accounts\Widgets;
use App\Filament\Resources\TransactionResource\Widgets\FinanceOverview;
use Filament\Pages\Dashboard;

/** Revenue, expenses and profit at a glance. */
class AccountsOverview extends Dashboard
{
    protected static string $routePath = 'accounts';

    protected static ?string $navigationGroup = 'Accounts & reports';

    protected static ?string $navigationLabel = 'Accounts overview';

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $title = 'Accounts';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return \App\Support\Access::allows('manage-finance');
    }

    public function getWidgets(): array
    {
        return [
            FinanceOverview::class,
            Widgets\RevenueVsExpenses::class,
            Widgets\RevenueByService::class,
            Widgets\ExpenseBreakdown::class,
            Widgets\TopEarners::class,
            Widgets\OutstandingPayments::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
