<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Enums\CustomerType;
use App\Enums\DocumentStatus;
use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCustomers extends ListRecords
{
    use \App\Filament\Concerns\SimpleTabs; // 6 Nov (S4): main tabs + "More views"

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Support\Exports\TableExport::action(),
            Actions\CreateAction::make()->label('Add customer'),
        ];
    }

    public function getTabs(): array
    {
        return $this->simpleTabs([
            'all' => Tab::make('Customers')
                ->modifyQueryUsing(fn (Builder $query) => $query->customers()),
            'prospects' => Tab::make('Prospects')
                ->icon('heroicon-o-user-plus')
                ->badge(fn () => Customer::where('status', 'prospect')->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'prospect')),
            'vip' => Tab::make('VIP')
                ->icon('heroicon-o-star')
                ->badge(fn () => Customer::where('is_vip', true)->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_vip', true)),
            'corporate' => Tab::make('Corporate & agencies')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', [CustomerType::Corporate, CustomerType::Agency])),
            'mine' => Tab::make('My customers')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('assigned_to', auth()->id())),
            'follow_up' => Tab::make('Follow-up due')
                ->icon('heroicon-o-clock')
                ->badge(fn () => Customer::followUpDue()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->followUpDue()),
            'never_logged_in' => Tab::make('Never logged in')
                ->icon('heroicon-o-key')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('id', \App\Models\User::query()->whereNotNull('customer_id')->whereNull('last_login_at')->where('login_enabled', true)->select('customer_id'))),
            'documents' => Tab::make('Documents to review')
                ->icon('heroicon-o-identification')
                ->badge(fn () => Customer::whereHas('documents', fn (Builder $q) => $q->where('status', DocumentStatus::Received))->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('documents', fn (Builder $q) => $q->where('status', DocumentStatus::Received))),
        ], ['all', 'follow_up', 'documents'], []);
    }
}
