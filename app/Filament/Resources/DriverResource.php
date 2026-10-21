<?php

namespace App\Filament\Resources;

use App\Enums\BookingStatus;
use App\Filament\Resources\DriverResource\Pages;
use App\Models\User;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Simple driver profiles: contact details, usual cars and their bookings.
 * Drivers are CRM users with the "Driver" role (they only see their own bookings).
 * No driver pricing, payroll or app.
 */
class DriverResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'drivers';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Drivers';

    protected static ?string $modelLabel = 'driver';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return Access::allows('manage-bookings');
    }

    public static function canCreate(): bool
    {
        return Access::allows('manage-fleet');
    }

    public static function canEdit(Model $record): bool
    {
        return Access::allows('manage-fleet');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // switch off instead, so bookings keep their driver
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->role('Driver');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Section::make('Driver')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('phone')->tel()->required()->maxLength(30),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique('users', 'email', ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('Used to log in to the CRM (they only see their own bookings).'),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->rule('min:8')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): string => $operation === 'create'
                                ? 'Optional. Leave empty if they will not log in; a random password is set.'
                                : 'Leave empty to keep the current password.'),
                        Forms\Components\Textarea::make('notes')
                            ->placeholder('Licence, languages, areas, anything useful')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Cars')
                    ->columnSpan(1)
                    ->schema([
                        Forms\Components\Select::make('assignedCars')
                            ->label('Usual cars')
                            ->relationship('assignedCars', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->helperText('Suggested first when one of these cars is booked with a driver. You can still pick anyone on the booking.'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive drivers are not offered on new bookings and cannot log in.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (User $record): ?string => $record->phone),
                Tables\Columns\TextColumn::make('assignedCars.name')
                    ->label('Usual cars')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('next_job')
                    ->label('Next job')
                    ->state(function (User $record): ?string {
                        $b = $record->driverBookings()->whereIn('status', BookingStatus::blocking())->where('end_at', '>=', now())->orderBy('start_at')->first();

                        return $b ? $b->start_at->format('d M, H:i').' · '.$b->reference : null;
                    })
                    ->placeholder('Free'),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('assignedCars'))
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active')->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\Action::make('whatsapp')
                    ->iconButton()
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->visible(fn (User $record): bool => filled($record->phone))
                    ->url(fn (User $record): ?string => \App\Support\Phone::whatsappUrl($record->phone), shouldOpenInNewTab: true),
            ])
            ->emptyStateIcon('heroicon-o-identification')
            ->emptyStateHeading('No drivers yet')
            ->emptyStateDescription('Add your drivers here, then pick them on bookings with a driver.');
    }

    public static function getRelations(): array
    {
        return [
            DriverResource\RelationManagers\DriverBookingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDrivers::route('/'),
            'create' => Pages\CreateDriver::route('/create'),
            'edit' => Pages\EditDriver::route('/{record}/edit'),
        ];
    }
}
