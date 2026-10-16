<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add lead'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            LeadResource\Widgets\LeadOverview::class,
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Open')
                ->icon('heroicon-o-inbox')
                ->modifyQueryUsing(fn (Builder $q) => $q->open()),
            'new' => Tab::make('New')
                ->badge(fn () => Lead::where('status', LeadStatus::New)->count() ?: null)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', LeadStatus::New)),
            'mine' => Tab::make('My leads')
                ->icon('heroicon-o-user')
                ->modifyQueryUsing(fn (Builder $q) => $q->open()->where('assigned_to', auth()->id())),
            'follow_up' => Tab::make('Follow-up due')
                ->icon('heroicon-o-clock')
                ->badge(fn () => Lead::followUpDue()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $q) => $q->followUpDue()),
            'unassigned' => Tab::make('Unassigned')
                ->modifyQueryUsing(fn (Builder $q) => $q->open()->whereNull('assigned_to')),
            'converted' => Tab::make('Converted')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', LeadStatus::Converted)),
            'lost' => Tab::make('Lost')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', LeadStatus::Lost)),
            'all' => Tab::make('All'),
        ];
    }
}
