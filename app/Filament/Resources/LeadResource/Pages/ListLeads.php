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
    use \App\Filament\Concerns\SimpleTabs; // 6 Nov (S4): main tabs + "More views"

    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Support\Exports\TableExport::action(),
            Actions\Action::make('board')
                ->label('Pipeline board')
                ->icon('heroicon-o-view-columns')
                ->color('gray')
                ->url(\App\Filament\Pages\PipelineBoard::getUrl()),
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
        // 5 Nov (S3): agents start on the leads waiting for them to accept, when there are any.
        return \App\Support\Access::agentScoped() && $this->waitingQuery()->exists() ? 'waiting' : 'open';
    }

    /** Leads nobody has accepted yet (sent to every agent), as this person may see them. */
    protected function waitingQuery(): Builder
    {
        return LeadResource::getEloquentQuery()->withoutTrashed()->open()->withoutLiveBooking()->whereNull('assigned_to')->whereNotNull('broadcast_at');
    }

    public function getTabs(): array
    {
        $scoped = fn () => LeadResource::getEloquentQuery()->withoutTrashed();

        return $this->simpleTabs([
            'waiting' => Tab::make('Waiting for an agent')
                ->icon('heroicon-o-hand-raised')
                ->badge(fn () => $this->waitingQuery()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->withoutLiveBooking()->whereNull('assigned_to')->whereNotNull('broadcast_at')),
            'open' => Tab::make(\App\Support\Access::agentScoped() ? 'My open leads' : 'Open')
                ->icon('heroicon-o-inbox')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->withoutLiveBooking()),
            'new' => Tab::make('New')
                ->badge(fn () => $scoped()->withoutLiveBooking()->where('status', LeadStatus::New)->count() ?: null)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->withoutLiveBooking()->where('status', LeadStatus::New)),
            'mine' => Tab::make('My leads')
                ->icon('heroicon-o-user')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->withoutLiveBooking()->where('assigned_to', auth()->id())),
            'follow_up' => Tab::make('Follow-up due')
                ->icon('heroicon-o-clock')
                ->badge(fn () => $scoped()->followUpDue()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->followUpDue()),
            'unassigned' => Tab::make('Unassigned')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->withoutLiveBooking()->whereNull('assigned_to')),
            'converted' => Tab::make('Won')
                ->icon('heroicon-o-trophy')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', LeadStatus::Converted)),
            'lost' => Tab::make('Lost')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', LeadStatus::Lost)),
            'all' => Tab::make('All'),
        ], ['waiting', 'open', 'converted', 'lost', 'all'], ['follow_up']);
    }
}
