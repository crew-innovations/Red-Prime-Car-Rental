<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Enums\CustomerType;
use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add customer'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'vip' => Tab::make('VIP')
                ->icon('heroicon-o-star')
                ->badge(fn () => Customer::where('is_vip', true)->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('is_vip', true)),
            'corporate' => Tab::make('Corporate & agencies')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereIn('type', [CustomerType::Corporate, CustomerType::Agency])),
            'mine' => Tab::make('My customers')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('assigned_to', auth()->id())),
            'follow_up' => Tab::make('Follow-up due')
                ->icon('heroicon-o-clock')
                ->badge(fn () => Customer::followUpDue()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $q) => $q->followUpDue()),
        ];
    }
}
