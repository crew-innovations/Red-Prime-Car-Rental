<?php

namespace App\Filament\Resources\CarResource\RelationManagers;

use App\Filament\Resources\TrafficFineResource;
use App\Support\Access;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** "Fines" tab on a car (4 Nov, 2.8). */
class FinesRelationManager extends RelationManager
{
    protected static string $relationship = 'fines';

    protected static ?string $title = 'Fines';

    protected static ?string $icon = 'heroicon-o-document-text';

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
            ->columns([
                Tables\Columns\TextColumn::make('offence_at')->label('When')->dateTime('d M Y H:i'),
                Tables\Columns\TextColumn::make('ticket_no')->label('Ticket')->description(fn ($r) => $r->authority),
                Tables\Columns\TextColumn::make('offence')->limit(50),
                Tables\Columns\TextColumn::make('amount')->money('AED'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('all')->label('All fines')->url(fn () => TrafficFineResource::getUrl('index', ['activeTab' => 'all'])),
            ]);
    }
}
