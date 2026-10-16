<?php

namespace App\Filament\Resources\TripResource\Pages;

use App\Enums\TripStatus;
use App\Filament\Resources\TripResource;
use App\Models\Category;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTrips extends ListRecords
{
    protected static string $resource = TripResource::class;

    protected function getHeaderActions(): array
    {
        $enabled = Category::isEnabled(Category::YACHT_TRIPS);

        return [
            // One-click switch for the whole Yacht Trips section on the website (Clause 4.6).
            Actions\Action::make('toggleSection')
                ->label($enabled ? 'Section is live on website' : 'Section hidden on website')
                ->icon($enabled ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
                ->color($enabled ? 'success' : 'warning')
                ->outlined()
                ->requiresConfirmation()
                ->modalHeading($enabled ? 'Hide Yacht Trips from the website?' : 'Show Yacht Trips on the website?')
                ->modalDescription($enabled
                    ? 'All trips, links and menu items for Yacht Trips will disappear from the website. Nothing is deleted.'
                    : 'Published trips will appear on the website again.')
                ->action(function () use ($enabled) {
                    // Saved through the model so the cached category map is refreshed.
                    Category::firstOrCreate(
                        ['slug' => Category::YACHT_TRIPS],
                        ['name' => 'Yacht Trips & Experiences', 'sort_order' => 3],
                    )->update(['is_enabled' => ! $enabled]);
                    $this->redirect(TripResource::getUrl('index'));
                }),
            Actions\CreateAction::make()->label('Add trip'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', TripStatus::Active)),
            'no_dates' => Tab::make('No upcoming dates')
                ->icon('heroicon-o-calendar')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', TripStatus::Active)->doesntHave('upcomingDates')),
            'paused' => Tab::make('Paused')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', TripStatus::Paused)),
            'hidden' => Tab::make('Hidden from website')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('is_published', false)),
        ];
    }
}
