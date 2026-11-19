<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\TrafficFineResource;
use App\Models\TrafficFine;
use App\Support\Access;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** 19 Nov: "Fines" tab on a customer (same list as on a car). */
class FinesRelationManager extends RelationManager
{
    protected static string $relationship = 'fines';

    protected static ?string $title = 'Fines';

    protected static ?string $icon = 'heroicon-o-receipt-percent';

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
        return $table->defaultSort('offence_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['car', 'booking']))
            ->columns([
                Tables\Columns\TextColumn::make('offence_at')->label('When')->dateTime('d M Y H:i'),
                Tables\Columns\TextColumn::make('ticket_no')->label('Ticket')->description(fn (TrafficFine $r) => $r->authority),
                Tables\Columns\TextColumn::make('car.name')->label('Car')->placeholder(fn (TrafficFine $r) => $r->plate ?: '—'),
                Tables\Columns\TextColumn::make('offence')->limit(40),
                Tables\Columns\TextColumn::make('amount')->money('AED')
                    ->description(fn (TrafficFine $r) => (float) $r->admin_fee > 0 ? '+ fee AED '.number_format((float) $r->admin_fee, 2) : null),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')->placeholder('—')
                    ->url(fn (TrafficFine $r) => $r->booking ? BookingResource::getUrl('edit', ['record' => $r->booking]) : null),
            ])
            ->actions([
                Tables\Actions\Action::make('open')->label('Open')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (TrafficFine $r) => TrafficFineResource::getUrl('edit', ['record' => $r])),
            ]);
    }
}
