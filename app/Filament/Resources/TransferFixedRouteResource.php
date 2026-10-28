<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedResource;
use App\Filament\Resources\TransferFixedRouteResource\Pages;
use App\Models\TransferFixedRoute;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Pricing › Fixed routes: a set price for a known route and vehicle category, used instead of the distance price. */
class TransferFixedRouteResource extends Resource
{
    use GuardedResource;

    protected static ?string $model = TransferFixedRoute::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Fixed routes';

    protected static ?string $modelLabel = 'fixed route';

    protected static ?string $pluralModelLabel = 'fixed routes';

    protected static ?int $navigationSort = 6;

    protected static function manageAbility(): string
    {
        return 'manage-pricing';
    }

    protected static function deleteAbility(): string
    {
        return 'manage-pricing';
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
            Forms\Components\TextInput::make('name')->required()->maxLength(120)->placeholder('DXB airport → Dubai Marina'),
            Forms\Components\Select::make('vehicle_category_id')->label('Vehicle category')->relationship('category', 'name')->required()->native(false),
            Forms\Components\TextInput::make('from_match')->label('Pickup contains')->required()->maxLength(255)->placeholder('DXB, Dubai International Airport')
                ->helperText('Any of these words (comma separated) in the pickup address.'),
            Forms\Components\TextInput::make('to_match')->label('Destination contains')->required()->maxLength(255)->placeholder('Dubai Marina, JBR'),
            Forms\Components\TextInput::make('price')->numeric()->minValue(0)->prefix('AED')->required(),
            Forms\Components\Toggle::make('both_ways')->label('Also the other way round')->default(true),
            Forms\Components\Toggle::make('is_active')->label('In use')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('category.name')->label('Category'),
                Tables\Columns\TextColumn::make('price')->money('AED')->sortable(),
                Tables\Columns\IconColumn::make('both_ways')->label('Both ways')->boolean(),
                Tables\Columns\ToggleColumn::make('is_active')->label('In use')->disabled(fn (): bool => ! static::canModify()),
            ])
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
        return ['index' => Pages\ManageTransferFixedRoutes::route('/')];
    }
}
