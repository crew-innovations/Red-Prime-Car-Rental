<?php

namespace App\Filament\Resources\HistoryResource\Pages;

use App\Filament\Resources\HistoryResource;
use App\Filament\Resources\HistoryResource\Widgets\AuditOverview;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListHistory extends ListRecords
{
    protected static string $resource = HistoryResource::class;

    protected ?string $subheading = 'Who did what, where and when. Lines are saved once and cannot be changed or deleted.';

    protected function getHeaderActions(): array
    {
        return [\App\Support\Exports\TableExport::action()];
    }

    protected function getHeaderWidgets(): array
    {
        return [AuditOverview::class];
    }

    /** 19 Nov: quick views. */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'changes' => Tab::make('Changes')->icon('heroicon-o-pencil-square')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('event', ['created', 'updated', 'restored', 'override', 'reassign', 'archive'])),
            'deletions' => Tab::make('Deletions')->icon('heroicon-o-trash')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('event', 'deleted')),
            'signins' => Tab::make('Sign-ins')->icon('heroicon-o-arrow-right-on-rectangle')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('event', ['login', 'logout', 'login_failed'])),
            'money' => Tab::make('Money')->icon('heroicon-o-banknotes')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn ($q) => $q->whereIn('auditable_type', ['transaction', 'invoice', 'payment_request', 'deposit_settlement', 'supplier_payment'])
                    ->orWhereIn('event', ['payment', 'refund', 'payment_link', 'supplier_bill', 'supplier_payment']))),
            'settings' => Tab::make('Settings & roles')->icon('heroicon-o-cog-6-tooth')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('event', ['settings', 'permissions', 'integration', 'invite'])),
            'problems' => Tab::make('Refused / failed')->icon('heroicon-o-exclamation-triangle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn ($q) => $q->whereIn('outcome', ['failed', 'denied'])->orWhere('event', 'login_failed'))),
        ];
    }
}
