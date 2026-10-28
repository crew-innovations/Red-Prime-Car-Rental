<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedResource;
use App\Filament\Resources\TransferRateResource\Pages;
use App\Models\TransferRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Pricing › Transfer rates: the limousine price list per vehicle category (brief 28 Oct-2, 4.4). Every amount is set here; nothing is assumed. */
class TransferRateResource extends Resource
{
    use GuardedResource;

    protected static ?string $model = TransferRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Transfer rates';

    protected static ?string $modelLabel = 'transfer rate';

    protected static ?string $pluralModelLabel = 'transfer rates';

    protected static ?int $navigationSort = 5;

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
            Forms\Components\Select::make('vehicle_category_id')->label('Vehicle category')->relationship('category', 'name')->required()->native(false),
            Forms\Components\Select::make('transfer_service_id')->label('Only for this service')->relationship('service', 'name')->placeholder('Every service')->native(false)
                ->helperText('A rate for one service wins over the general rate.'),
            Forms\Components\TextInput::make('name')->label('Name (internal)')->maxLength(120)->columnSpanFull(),
            Forms\Components\Fieldset::make('By distance')->columns(3)->schema([
                    Forms\Components\TextInput::make('base_fee')->label('Base fee')->numeric()->minValue(0)->prefix('AED'),
                    Forms\Components\TextInput::make('per_km')->label('Per km')->numeric()->minValue(0)->prefix('AED'),
                    Forms\Components\TextInput::make('min_fare')->label('Minimum fare')->numeric()->minValue(0)->prefix('AED'),
            ]),
            Forms\Components\Fieldset::make('By the hour')->columns(3)->schema([
                    Forms\Components\TextInput::make('per_hour')->label('Per hour')->numeric()->minValue(0)->prefix('AED'),
                    Forms\Components\TextInput::make('min_hours')->label('Minimum hours')->numeric()->minValue(0),
                    Forms\Components\Placeholder::make('h_hint')->hiddenLabel()->content('The cheapest that fits is used.'),
                    Forms\Components\TextInput::make('half_day_price')->label('Half-day price')->numeric()->minValue(0)->prefix('AED'),
                    Forms\Components\TextInput::make('half_day_hours')->label('Half day = up to (hours)')->numeric()->minValue(0),
                    Forms\Components\Placeholder::make('h2')->hiddenLabel()->content(''),
                    Forms\Components\TextInput::make('full_day_price')->label('Full-day price')->numeric()->minValue(0)->prefix('AED'),
                    Forms\Components\TextInput::make('full_day_hours')->label('Full day = up to (hours)')->numeric()->minValue(0),
            ]),
            Forms\Components\Fieldset::make('Extras')->columns(3)->schema([
                    Forms\Components\TextInput::make('airport_fee')->label('Airport fee')->numeric()->minValue(0)->prefix('AED')->helperText('Airport services only.'),
                    Forms\Components\TextInput::make('per_stop')->label('Per extra stop')->numeric()->minValue(0)->prefix('AED'),
                    Forms\Components\TextInput::make('toll_fee')->label('Tolls per journey')->numeric()->minValue(0)->prefix('AED'),
                    Forms\Components\TextInput::make('free_waiting_minutes')->label('Free waiting (min)')->numeric()->minValue(0),
                    Forms\Components\TextInput::make('waiting_per_hour')->label('Waiting per hour')->numeric()->minValue(0)->prefix('AED')->helperText('Charged in 15-minute steps after the free minutes.'),
                    Forms\Components\TextInput::make('return_discount_percent')->label('Return journey discount')->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                    Forms\Components\TextInput::make('night_percent')->label('Night surcharge')->numeric()->minValue(0)->maxValue(200)->suffix('%'),
                    Forms\Components\TimePicker::make('night_from')->label('Night from')->seconds(false),
                    Forms\Components\TimePicker::make('night_to')->label('Night until')->seconds(false),
            ]),
            Forms\Components\DatePicker::make('starts_on')->label('Valid from')->native(false)->helperText('Empty = always. Bookings already quoted keep their price.'),
            Forms\Components\DatePicker::make('ends_on')->label('Valid until')->native(false)->afterOrEqual('starts_on'),
            Forms\Components\Toggle::make('is_active')->label('In use')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('category.name')->label('Category')->weight('semibold')->sortable(),
                Tables\Columns\TextColumn::make('service.name')->label('Service')->placeholder('Every service'),
                Tables\Columns\TextColumn::make('base_fee')->label('Base')->money('AED'),
                Tables\Columns\TextColumn::make('per_km')->label('Per km')->money('AED'),
                Tables\Columns\TextColumn::make('min_fare')->label('Minimum')->money('AED'),
                Tables\Columns\TextColumn::make('per_hour')->label('Per hour')->money('AED'),
                Tables\Columns\TextColumn::make('starts_on')->label('From')->date('d M Y')->placeholder('Always'),
                Tables\Columns\TextColumn::make('ends_on')->label('Until')->date('d M Y')->placeholder('-'),
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
        return ['index' => Pages\ManageTransferRates::route('/')];
    }
}
