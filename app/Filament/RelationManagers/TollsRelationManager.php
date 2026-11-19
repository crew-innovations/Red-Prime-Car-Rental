<?php

namespace App\Filament\RelationManagers;

use App\Filament\Resources\BookingResource;
use App\Models\TollTrip;
use App\Support\Access;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** 19 Nov: "Tolls" tab (Salik / Darb / parking) on a car and on a customer. Charging stays in Tolls. */
class TollsRelationManager extends RelationManager
{
    protected static string $relationship = 'tollTrips';

    protected static ?string $title = 'Tolls';

    protected static ?string $icon = 'heroicon-o-ticket';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-fines');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('passed_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['car', 'booking']))
            ->columns([
                Tables\Columns\TextColumn::make('passed_at')->label('When')->dateTime('d M Y H:i'),
                Tables\Columns\TextColumn::make('toll_type')->label('Type')->formatStateUsing(fn (?string $state) => TollTrip::TYPES[$state] ?? $state)
                    ->description(fn (TollTrip $r) => $r->gate),
                Tables\Columns\TextColumn::make('car.name')->label('Car')->placeholder(fn (TollTrip $r) => $r->plate ?: '—'),
                Tables\Columns\TextColumn::make('amount')->money('AED')->summarize(Tables\Columns\Summarizers\Sum::make()->money('AED')),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => TollTrip::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => TollTrip::COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')->placeholder('—')
                    ->url(fn (TollTrip $r) => $r->booking ? BookingResource::getUrl('edit', ['record' => $r->booking]) : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(TollTrip::STATUSES),
            ])
            ->headerActions([
                Tables\Actions\Action::make('all')->label('All tolls')->url(fn () => \App\Filament\Resources\TollTripResource::getUrl('index')),
            ]);
    }
}
