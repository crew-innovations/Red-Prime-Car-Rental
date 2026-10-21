<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\BookingResource\BookingActions;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Lead;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** "Bookings" tab on cars, yachts, trips, customers and leads. */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Bookings';

    protected static ?string $icon = 'heroicon-o-calendar-days';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->bookings()->whereIn('status', BookingStatus::blocking())->where('end_at', '>=', now())->count();

        return $count ? (string) $count : null;
    }

    public function isReadOnly(): bool
    {
        return ! \App\Support\Access::allows('manage-bookings');
    }

    protected function createUrl(): string
    {
        $owner = $this->getOwnerRecord();

        return BookingResource::getUrl('create', match (true) {
            $owner instanceof Customer => ['customer' => $owner->id],
            $owner instanceof Lead => ['lead' => $owner->id],
            default => ['service' => $owner->getMorphClass(), 'item' => $owner->id],
        });
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('reference')
            ->columns(BookingResource::tableColumns(
                withItem: ! in_array($owner->getMorphClass(), ['car', 'yacht', 'trip', 'package'], true),
                withCustomer: ! $owner instanceof Customer,
            ))
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'bookable', 'driver']))
            ->defaultSort('start_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('new')
                    ->label('New booking')
                    ->icon('heroicon-o-plus')
                    ->authorize('manage-bookings')
                    ->url(fn (): string => $this->createUrl()),
            ])
            ->actions([
                BookingActions::deliver(Tables\Actions\Action::class)->button()->size('sm'),
                BookingActions::receive(Tables\Actions\Action::class)->button()->size('sm'),
                Tables\Actions\Action::make('open')
                    ->iconButton()
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->tooltip('Open booking')
                    ->url(fn (Booking $record): string => BookingResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading('No bookings yet');
    }
}
