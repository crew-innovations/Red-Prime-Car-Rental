<?php

namespace App\Filament\Resources\CarResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MaintenancesRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenances';

    protected static ?string $title = 'Maintenance log';

    protected static ?string $icon = 'heroicon-o-wrench-screwdriver';

    public function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\DatePicker::make('date')
                    ->default(today())
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('cost')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('AED'),
                Forms\Components\TextInput::make('title')
                    ->placeholder('e.g. Oil change, tyre replacement')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->description(fn ($record) => str($record->description)->limit(60)->toString() ?: null)
                    ->searchable(),
                Tables\Columns\TextColumn::make('cost')
                    ->money('AED')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('AED')->label('Total')),
            ])
            ->defaultSort('date', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Add entry'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->emptyStateHeading('No maintenance recorded')
            ->emptyStateDescription('Log services, repairs and costs for this car.');
    }
}
