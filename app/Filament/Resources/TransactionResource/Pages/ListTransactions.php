<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Filament\Resources\TransactionResource;
use App\Models\Transaction;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make('income')
                ->label('Record income')
                ->icon('heroicon-o-arrow-down-left')
                ->url(TransactionResource::getUrl('create', ['type' => TransactionType::Income->value])),
            Actions\CreateAction::make('expense')
                ->label('Record expense')
                ->icon('heroicon-o-arrow-up-right')
                ->color('gray')
                ->url(TransactionResource::getUrl('create', ['type' => TransactionType::Expense->value])),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return TransactionResource::getWidgets();
    }

    public function getTabs(): array
    {
        $outstanding = fn (Builder $query) => $query->where('type', TransactionType::Income)->whereNull('reversed_at')
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Overdue, PaymentStatus::PartiallyPaid]);

        return [
            'all' => Tab::make('All'),
            'month' => Tab::make('This month')
                ->icon('heroicon-o-calendar')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])),
            'income' => Tab::make('Income')
                ->icon('heroicon-o-arrow-down-left')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', TransactionType::Income)),
            'expense' => Tab::make('Expenses')
                ->icon('heroicon-o-arrow-up-right')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', TransactionType::Expense)),
            'approval' => Tab::make('Waiting for approval')
                ->icon('heroicon-o-check-badge')
                ->badge(fn () => Transaction::query()->where('approval_status', 'pending')->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('approval_status', 'pending')),
            'estimates' => Tab::make('Estimates')
                ->icon('heroicon-o-calculator')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_estimate', true)->whereNull('reversed_at')),
            'outstanding' => Tab::make('To collect')
                ->icon('heroicon-o-clock')
                ->badge(fn () => $outstanding(Transaction::query())->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $outstanding($query)),
        ];
    }
}
