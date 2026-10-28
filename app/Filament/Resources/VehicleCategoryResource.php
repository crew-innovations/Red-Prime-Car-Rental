<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedResource;
use App\Filament\Resources\VehicleCategoryResource\Pages;
use App\Models\VehicleCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Limousine › Vehicle categories: what the customer books (sedan, SUV, van); the rates and the cars use them. */
class VehicleCategoryResource extends Resource
{
    use GuardedResource;

    protected static ?string $model = VehicleCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Limousine';

    protected static ?string $navigationLabel = 'Vehicle categories';

    protected static ?string $modelLabel = 'vehicle category';

    protected static ?string $pluralModelLabel = 'vehicle categories';

    protected static ?int $navigationSort = 3;

    protected static function manageAbility(): string
    {
        return 'manage-fleet';
    }

    protected static function deleteAbility(): string
    {
        return 'manage-fleet';
    }

    public static function canEdit(Model $record): bool
    {
        return static::canModify();
    }

    public static function canViewAny(): bool
    {
        return ! \App\Support\Access::isDriverOnly() && ! \App\Support\Access::agentScoped();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(120)->datalist(['Business sedan', 'First class sedan', 'Luxury SUV', 'Van (7 seats)', 'Sprinter', 'Stretch limousine']),
            Forms\Components\TextInput::make('passengers')->label('Passengers (max)')->numeric()->minValue(1)->maxValue(60),
            Forms\Components\TextInput::make('luggage')->label('Bags (max)')->numeric()->minValue(0)->maxValue(60),
            Forms\Components\Textarea::make('description')->rows(2)->columnSpanFull()->placeholder('Mercedes S-Class or similar'),
            Forms\Components\Toggle::make('is_active')->label('Offered')->default(true),
            Forms\Components\Toggle::make('is_published')->label('Show on the website'),
            Forms\Components\TextInput::make('sort_order')->label('Order')->numeric()->default(0),
            Forms\Components\Placeholder::make('cars_hint')->hiddenLabel()->columnSpanFull()
                ->content('Put cars in a category on the car page (Vehicle category). Transfers can then be given one of those cars.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('passengers')->label('Passengers'),
                Tables\Columns\TextColumn::make('luggage')->label('Bags'),
                Tables\Columns\TextColumn::make('cars_count')->counts('cars')->label('Cars'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Offered')->disabled(fn (): bool => ! static::canModify()),
                Tables\Columns\ToggleColumn::make('is_published')->label('Website')->disabled(fn (): bool => ! static::canModify()),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order', fn (): bool => static::canModify())
            ->filters([Tables\Filters\TrashedFilter::make()])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
                Tables\Actions\RestoreAction::make()->iconButton(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageVehicleCategories::route('/')];
    }
}
