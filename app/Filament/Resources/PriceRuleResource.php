<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedResource;
use App\Filament\Resources\PriceRuleResource\Pages;
use App\Models\Car;
use App\Models\Package;
use App\Models\PriceRule;
use App\Models\Trip;
use App\Models\Yacht;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Pricing › Special rates: seasons, special dates, weekends, category rates. */
class PriceRuleResource extends Resource
{
    use GuardedResource;

    protected static ?string $model = PriceRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Special rates';

    protected static ?string $modelLabel = 'special rate';

    protected static ?int $navigationSort = 2;

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

    public static function serviceOptions(): array
    {
        return ['car' => 'Cars', 'yacht' => 'Yacht charters', 'trip' => 'Yacht trips', 'package' => 'Trips & packages'];
    }

    public static function itemOptions(?string $service): array
    {
        $class = match ($service) {
            'yacht' => Yacht::class,
            'trip' => Trip::class,
            'package' => Package::class,
            default => Car::class,
        };

        return $class::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')
                ->label('Name')
                ->placeholder('Summer 2027, Eid weekend, New Year week…')
                ->required()->maxLength(120)
                ->columnSpanFull(),
            Forms\Components\ToggleButtons::make('service_type')
                ->label('For')
                ->options(self::serviceOptions())
                ->default('car')
                ->inline()->required()->live()
                ->afterStateUpdated(function (Set $set) {
                    $set('item_id', null);
                    $set('category', null);
                    $set('unit', null);
                })
                ->columnSpanFull(),
            Forms\Components\ToggleButtons::make('applies_to')
                ->label('Applies to')
                ->options(fn (Get $get): array => in_array($get('service_type'), ['car', 'yacht'], true)
                    ? ['item' => 'One '.($get('service_type') === 'yacht' ? 'yacht' : 'car'), 'category' => 'A type', 'all' => 'All']
                    : ['item' => 'One trip', 'all' => 'All'])
                ->default('item')
                ->inline()->required()->live(),
            Forms\Components\Select::make('item_id')
                ->label(fn (Get $get): string => match ($get('service_type')) {
                    'yacht' => 'Yacht', 'trip' => 'Yacht trip', 'package' => 'Trip / package', default => 'Car',
                })
                ->options(fn (Get $get): array => self::itemOptions($get('service_type')))
                ->searchable()
                ->required(fn (Get $get): bool => $get('applies_to') === 'item')
                ->visible(fn (Get $get): bool => $get('applies_to') === 'item'),
            Forms\Components\Select::make('category')
                ->label('Type')
                ->options(fn (Get $get): array => collect(\App\Support\Options::list($get('service_type') === 'yacht' ? 'yacht_types' : 'car_types'))->mapWithKeys(fn ($t) => [$t => $t])->all())
                ->searchable()
                ->required(fn (Get $get): bool => $get('applies_to') === 'category')
                ->visible(fn (Get $get): bool => $get('applies_to') === 'category'),
            Forms\Components\ToggleButtons::make('drive_mode')
                ->label('Rental type')
                ->options(['any' => 'Both', 'self_drive' => 'Self-drive', 'with_driver' => 'With driver'])
                ->default('any')
                ->inline()
                ->visible(fn (Get $get): bool => $get('service_type') === 'car')
                ->columnSpanFull(),

            Forms\Components\Fieldset::make('Rate')->columns(3)->schema([
                Forms\Components\ToggleButtons::make('method')
                    ->label('How')
                    ->options(['set' => 'Set a rate', 'percent' => 'Up / down by %'])
                    ->default('set')
                    ->inline()->required()->live(),
                Forms\Components\Select::make('unit')
                    ->label('Which rate')
                    ->options(fn (Get $get): array => collect(PriceRule::UNITS[$get('service_type') ?? 'car'] ?? [])
                        ->when($get('method') === 'set', fn ($c) => $c->except('any'))->all())
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('amount')
                    ->label(fn (Get $get): string => $get('method') === 'percent' ? 'Percent' : 'Rate')
                    ->numeric()
                    ->prefix(fn (Get $get): ?string => $get('method') === 'percent' ? null : 'AED')
                    ->suffix(fn (Get $get): ?string => $get('method') === 'percent' ? '%' : null)
                    ->helperText(fn (Get $get): ?string => $get('method') === 'percent' ? '20 = 20% more, -10 = 10% cheaper.' : null)
                    ->minValue(fn (Get $get): ?int => $get('method') === 'percent' ? -100 : 0)
                    ->required(),
                Forms\Components\TextInput::make('min_units')
                    ->label('Charge at least')
                    ->numeric()->minValue(0)
                    ->helperText('Optional. E.g. 3 = at least 3 days while this rate applies.')
                    ->columnSpan(2),
            ]),

            Forms\Components\Fieldset::make('When')->columns(2)->schema([
                Forms\Components\DatePicker::make('starts_on')->label('From')->native(false)->displayFormat('d M Y'),
                Forms\Components\DatePicker::make('ends_on')->label('Until')->native(false)->displayFormat('d M Y')->afterOrEqual('starts_on'),
                Forms\Components\CheckboxList::make('weekdays')
                    ->label('Only on these days')
                    ->helperText('Leave empty for every day. The rental start day decides.')
                    ->options(PriceRule::WEEKDAYS)
                    ->columns(7)
                    ->columnSpanFull(),
            ]),

            Forms\Components\TextInput::make('priority')
                ->numeric()->default(0)
                ->helperText('When two special rates match, the higher number wins.'),
            Forms\Components\Toggle::make('is_active')->label('Active')->default(true)->inline(false),
            Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->description(fn (PriceRule $r): string => self::serviceOptions()[$r->service_type] ?? $r->service_type),
                Tables\Columns\TextColumn::make('scope')
                    ->label('Applies to')
                    ->state(fn (PriceRule $r): string => $r->scopeLabel().($r->drive_mode && $r->drive_mode !== 'any' ? ' · '.str_replace('_', '-', $r->drive_mode) : '')),
                Tables\Columns\TextColumn::make('effect')
                    ->label('Rate')
                    ->state(fn (PriceRule $r): string => $r->effectText())
                    ->badge()
                    ->color(fn (PriceRule $r): string => $r->method === 'percent' && (float) $r->amount < 0 ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('when')
                    ->label('When')
                    ->state(fn (PriceRule $r): string => $r->whenText())
                    ->color(fn (PriceRule $r): ?string => $r->ends_on && $r->ends_on->isPast() ? 'gray' : null),
                Tables\Columns\TextColumn::make('priority')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active')->disabled(fn (): bool => ! static::canModify()),
            ])
            ->defaultSort('starts_on', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('service_type')->label('For')->options(self::serviceOptions()),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
                Tables\Filters\Filter::make('current')
                    ->label('In force today')
                    ->query(fn (Builder $q) => $q->where('is_active', true)
                        ->where(fn ($w) => $w->whereNull('starts_on')->orWhereDate('starts_on', '<=', today()))
                        ->where(fn ($w) => $w->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ReplicateAction::make()->label('Copy')->excludeAttributes(['created_by'])
                        ->beforeReplicaSaved(fn (PriceRule $replica) => $replica->name = $replica->name.' (copy)'),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-sparkles')
            ->emptyStateHeading('No special rates yet')
            ->emptyStateDescription('Standard rates are on each car, yacht and trip. Add a special rate for a season, special dates or a whole type of car.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePriceRules::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
