<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedResource;
use App\Filament\Resources\TransferServiceResource\Pages;
use App\Models\TransferService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Limousine › Services: airport pickup, hourly chauffeur… Admins add, switch off and order them (brief 28 Oct-2, 4.1). */
class TransferServiceResource extends Resource
{
    use GuardedResource;

    protected static ?string $model = TransferService::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Limousine';

    protected static ?string $navigationLabel = 'Services';

    protected static ?string $modelLabel = 'service';

    protected static ?string $pluralModelLabel = 'services';

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

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(120)
                ->datalist(['Airport pickup', 'Airport drop-off', 'Hotel transfer', 'Point-to-point transfer', 'Hourly chauffeur', 'Half-day chauffeur', 'Full-day chauffeur', 'Corporate transfer', 'Event transfer', 'Intercity transfer']),
            Forms\Components\Select::make('journey_type')->label('Priced as')->options(TransferService::JOURNEYS)->default('one_way')->required()->native(false)
                ->helperText('By the hour uses the hourly / half-day / full-day prices; the others use distance or a fixed route.'),
            Forms\Components\Toggle::make('is_airport')->label('Airport service (flight number, terminal, airport fee)'),
            Forms\Components\Toggle::make('needs_destination')->label('Destination required')->default(true),
            Forms\Components\Textarea::make('description')->rows(2)->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')->label('Offered')->default(true),
            Forms\Components\Toggle::make('is_published')->label('Show on the website')->helperText('Only through the website API; nothing is booked automatically.'),
            Forms\Components\TextInput::make('sort_order')->label('Order')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('journey_type')->label('Priced as')->formatStateUsing(fn (string $state): string => TransferService::JOURNEYS[$state] ?? $state)->badge(),
                Tables\Columns\IconColumn::make('is_airport')->label('Airport')->boolean(),
                Tables\Columns\ToggleColumn::make('is_active')->label('Offered')->disabled(fn (): bool => ! static::canModify()),
                Tables\Columns\ToggleColumn::make('is_published')->label('Website')->disabled(fn (): bool => ! static::canModify()),
                Tables\Columns\TextColumn::make('sort_order')->label('Order')->sortable(),
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
        return ['index' => Pages\ManageTransferServices::route('/')];
    }
}
