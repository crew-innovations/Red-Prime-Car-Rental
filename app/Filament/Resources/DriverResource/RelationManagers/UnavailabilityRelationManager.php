<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use App\Models\DriverUnavailability;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** "Not available" tab on a driver (4 Nov, 15): leave, days off. Jobs can't be given to them then. */
class UnavailabilityRelationManager extends RelationManager
{
    protected static string $relationship = 'unavailability';

    protected static ?string $title = 'Not available';

    protected static ?string $icon = 'heroicon-o-no-symbol';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! Access::isDriverOnly() && (Access::allows('manage-drivers') || Access::allows('dispatch-drivers'));
    }

    public function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\DateTimePicker::make('starts_at')->label('From')->seconds(false)->required(),
            Forms\Components\DateTimePicker::make('ends_at')->label('Until')->seconds(false)->required()->after('starts_at'),
            Forms\Components\Select::make('reason')->options(array_combine(DriverUnavailability::REASONS, DriverUnavailability::REASONS))->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('starts_at')->label('From')->dateTime('D d M Y H:i'),
                Tables\Columns\TextColumn::make('ends_at')->label('Until')->dateTime('D d M Y H:i'),
                Tables\Columns\TextColumn::make('reason')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_by')->label('Added by')
                    ->formatStateUsing(fn ($state, DriverUnavailability $record) => (int) $state === (int) $record->user_id ? 'The driver' : (\App\Models\User::find($state)?->name ?? '—')),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Add not-available time')
                    ->mutateFormDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
