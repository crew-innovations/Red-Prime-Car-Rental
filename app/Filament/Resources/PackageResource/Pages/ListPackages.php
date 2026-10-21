<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Enums\TripStatus;
use App\Filament\Resources\PackageResource;
use App\Models\Category;
use App\Models\Package;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPackages extends ListRecords
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        $enabled = Category::isEnabled(Package::CATEGORY);

        return [
            // One-click switch for the whole Trips & Packages section on the website.
            Actions\Action::make('toggleSection')
                ->label($enabled ? 'Section is live on website' : 'Section hidden on website')
                ->icon($enabled ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
                ->color($enabled ? 'success' : 'warning')
                ->outlined()
                ->visible(fn (): bool => PackageResource::canModify())
                ->requiresConfirmation()
                ->modalHeading($enabled ? 'Hide Trips & Packages from the website?' : 'Show Trips & Packages on the website?')
                ->modalDescription($enabled
                    ? 'The website will stop listing all packages. Nothing is deleted.'
                    : 'Published packages will appear on the website again.')
                ->action(function () use ($enabled) {
                    Category::firstOrCreate(['slug' => Package::CATEGORY], ['name' => 'Trips & Packages', 'sort_order' => 4])
                        ->update(['is_enabled' => ! $enabled]);
                    $this->redirect(PackageResource::getUrl('index'));
                }),
            Actions\CreateAction::make()->label('Add package'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TripStatus::Active)),
            'no_dates' => Tab::make('No upcoming dates')
                ->icon('heroicon-o-calendar')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TripStatus::Active)->where('booking_mode', 'departures')->doesntHave('upcomingDepartures')),
            'hidden' => Tab::make('Hidden from website')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', false)),
        ];
    }
}
