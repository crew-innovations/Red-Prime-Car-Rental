<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedResource;
use App\Filament\Resources\PriceExtraResource\Pages;
use App\Models\PriceExtra;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Pricing › Extras & fees: delivery and collection fees, optional extras, always-added fees. */
class PriceExtraResource extends Resource
{
    use GuardedResource;

    protected static ?string $model = PriceExtra::class;

    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Extras & fees';

    protected static ?string $modelLabel = 'extra or fee';

    protected static ?string $pluralModelLabel = 'extras & fees';

    protected static ?int $navigationSort = 3;

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
            Forms\Components\TextInput::make('name')->required()->maxLength(120)
                ->datalist(['Delivery in Dubai', 'Collection in Dubai', 'Airport delivery', 'Additional driver', 'Child seat', 'Decoration', 'Catering']),
            Forms\Components\Select::make('kind')
                ->label('Type')
                ->options(PriceExtra::KINDS)
                ->default('extra')
                ->required()
                ->native(false)
                ->helperText(fn (Forms\Get $get): string => match ($get('kind')) {
                    'delivery' => 'Added when "We deliver" is picked. A car\'s own delivery fee wins.',
                    'collection' => 'Added when "We collect" is picked.',
                    'fee' => 'Always added to every booking of the chosen services.',
                    default => 'The customer can choose it on a quotation or booking.',
                })
                ->live(),
            Forms\Components\TextInput::make('amount')->numeric()->minValue(0)->prefix('AED')->required(),
            Forms\Components\Select::make('unit')->options(PriceExtra::UNITS)->default('per_booking')->required()->native(false),
            Forms\Components\CheckboxList::make('service_types')
                ->label('For')
                ->helperText('Leave empty for every service.')
                ->options(PriceRuleResource::serviceOptions() + ['transfer' => 'Limousine & transfers'])
                ->columns(4)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('description')->rows(2)->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->label('Active')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight(FontWeight::SemiBold)->searchable()
                    ->description(fn (PriceExtra $e): ?string => $e->description),
                Tables\Columns\TextColumn::make('kind')->label('Type')->badge()
                    ->formatStateUsing(fn (string $state): string => PriceExtra::KINDS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'delivery', 'collection' => 'info', 'fee' => 'warning', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('amount')->money('AED')
                    ->description(fn (PriceExtra $e): string => PriceExtra::UNITS[$e->unit] ?? $e->unit),
                Tables\Columns\TextColumn::make('service_types')->label('For')
                    ->state(fn (PriceExtra $e): string => empty($e->service_types) ? 'Everything' : collect($e->service_types)->map(fn ($s) => PriceRuleResource::serviceOptions()[$s] ?? $s)->implode(', ')),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active')->disabled(fn (): bool => ! static::canModify()),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->label('Type')->options(PriceExtra::KINDS),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
                Tables\Actions\RestoreAction::make()->iconButton(),
            ])
            ->emptyStateIcon('heroicon-o-plus-circle')
            ->emptyStateHeading('No extras or fees yet')
            ->emptyStateDescription('Add delivery and collection fees, and extras like an additional driver. Add-ons saved on a car or yacht still work too.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePriceExtras::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
