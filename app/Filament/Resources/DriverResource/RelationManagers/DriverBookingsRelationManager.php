<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** The driver's bookings (read-only list; open the booking to change it). */
class DriverBookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'driverBookings';

    protected static ?string $title = 'Bookings';

    protected static ?string $icon = 'heroicon-o-calendar-days';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns(BookingResource::tableColumns())
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'bookable']))
            ->defaultSort('start_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->iconButton()
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->tooltip('Open booking')
                    ->url(fn (Booking $record): string => BookingResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('No bookings yet');
    }
}
