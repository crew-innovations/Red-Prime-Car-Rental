<?php

namespace App\Filament\Resources;

use App\Enums\BookingStatus;
use App\Filament\Resources\DriverResource\Pages;
use App\Models\DriverProfile;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access;
use App\Support\Drivers\DriverPapers;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Driver profiles: contact, personal and employment details, documents with
 * expiry dates, usual cars, their login and their jobs. Drivers are CRM users
 * with the "Driver" role and only ever see their own jobs and profile.
 * No driver pricing, payroll or separate app.
 */
class DriverResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'drivers';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Bookings & drivers';

    protected static ?string $navigationLabel = 'Drivers & crew';

    protected static ?string $modelLabel = 'driver';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        // 5 Nov (S3): agents see the driver on their own booking, not the drivers list with everyone's jobs.
        return Access::allows('manage-drivers') || (Access::allows('manage-bookings') && ! Access::agentScoped());
    }

    public static function canCreate(): bool
    {
        return Access::allows('manage-drivers');
    }

    public static function canEdit(Model $record): bool
    {
        return Access::allows('manage-drivers');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // switch off instead, so bookings and jobs keep their driver
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->role('Driver');
    }

    /** Required-field rule from Business settings › Drivers. */
    protected static function req(string $field): \Closure
    {
        return fn (): bool => in_array($field, (array) Setting::read('driver_required_fields', []), true);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Group::make()->columnSpan(2)->schema([
                    Forms\Components\Section::make('Driver')
                        ->columns(2)
                        ->schema([
                            Forms\Components\TextInput::make('name')->label('Name used in the CRM')->required()->maxLength(255),
                            Forms\Components\TextInput::make('phone')->label('Phone number')->tel()->required()->maxLength(30),
                            Forms\Components\TextInput::make('email')
                                ->email()
                                ->required()
                                ->unique('users', 'email', ignoreRecord: true)
                                ->maxLength(255)
                                ->helperText('Their login. Send them a login link from this page; nobody types a password for them.'),
                        ]),
                    Forms\Components\Section::make('Personal & employment details')
                        ->relationship('driverProfile')
                        ->columns(2)
                        ->collapsible()
                        ->schema([
                            Forms\Components\CheckboxList::make('work_types')
                                ->label('Can work as')
                                ->options(\App\Models\DriverProfile::WORK_TYPES)
                                ->default(['driver'])
                                ->helperText('Captains and crew can be put on yacht charters. Nothing ticked = driver.')
                                ->columns(3)
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('legal_name')->label('Full legal name (as on passport)')->maxLength(255)->required(self::req('legal_name')),
                            Forms\Components\DatePicker::make('date_of_birth')->native(false)->displayFormat('d M Y')->maxDate(today())->required(self::req('date_of_birth')),
                            Forms\Components\TextInput::make('nationality')->maxLength(80)->datalist(['United Arab Emirates', 'Pakistan', 'India', 'Philippines', 'Bangladesh', 'Egypt', 'Nepal', 'Sri Lanka'])->required(self::req('nationality')),
                            Forms\Components\Select::make('gender')->options(['male' => 'Male', 'female' => 'Female'])->native(false)->required(self::req('gender'))
                                ->helperText('Only if you need it for work or legal reasons.'),
                            Forms\Components\Textarea::make('address')->label('Residential address')->rows(2)->columnSpanFull()->required(self::req('address')),
                            Forms\Components\TextInput::make('emergency_name')->label('Emergency contact name')->maxLength(120)->required(self::req('emergency_name')),
                            Forms\Components\TextInput::make('emergency_phone')->label('Emergency contact phone')->tel()->maxLength(30)->required(self::req('emergency_phone')),
                            Forms\Components\TextInput::make('employee_ref')->label('Driver ID / employee ref')->maxLength(50)->required(self::req('employee_ref')),
                            Forms\Components\DatePicker::make('joined_on')->label('Joining date')->native(false)->displayFormat('d M Y')->required(self::req('joined_on')),
                            Forms\Components\SpatieMediaLibraryFileUpload::make('photo')
                                ->label('Profile photo')
                                ->collection('photo')->disk('private')->visibility('private')
                                ->image()->avatar()->maxSize(5120)
                                ->required(self::req('photo')),
                        ]),
                    Forms\Components\Section::make('Operational notes')
                        ->collapsible()
                        ->schema([
                            Forms\Components\Textarea::make('notes')->hiddenLabel()
                                ->placeholder('Languages, areas, anything useful for the office')
                                ->rows(3),
                        ]),
                ]),
                Forms\Components\Group::make()->columnSpan(1)->schema([
                    Forms\Components\Section::make('Status')
                        ->schema([
                            Forms\Components\Toggle::make('is_active')
                                ->label('Active driver')
                                ->default(true)
                                ->helperText('Inactive drivers get no new jobs and cannot log in. Their history stays.'),
                            Forms\Components\Toggle::make('login_enabled')
                                ->label('Can log in')
                                ->default(true)
                                ->helperText('Switch off to stop access only, e.g. while a document is renewed.'),
                            Forms\Components\Placeholder::make('login_state')
                                ->label('Login')
                                ->content(fn (?User $record): string => self::loginState($record))
                                ->visibleOn('edit'),
                            Forms\Components\Placeholder::make('papers')
                                ->label('Documents')
                                ->content(fn (?User $record): string => $record ? (($p = DriverPapers::problems($record)) ? implode(', ', $p) : 'Complete') : '-')
                                ->visibleOn('edit'),
                        ]),
                    Forms\Components\Section::make('Cars')
                        ->schema([
                            Forms\Components\Select::make('assignedCars')
                                ->label('Usual / default cars')
                                ->relationship('assignedCars', 'name')
                                ->multiple()
                                ->preload()
                                ->searchable()
                                ->helperText('Suggested first when one of these cars is booked. You can still pick anyone on the booking.'),
                        ]),
                ]),
            ]);
    }

    public static function loginState(?User $user): string
    {
        if (! $user) {
            return '-';
        }

        return match (true) {
            ! $user->is_active => 'Off (driver inactive)',
            ! $user->login_enabled => 'Switched off',
            ! Setting::read('driver_login_enabled', true) => 'Driver logins are switched off in Business settings',
            (bool) $user->last_login_at => 'Last login '.$user->last_login_at->diffForHumans(),
            (bool) $user->invited_at => 'Link sent '.$user->invited_at->diffForHumans().', not logged in yet',
            default => 'No login link sent yet',
        };
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
                        $job = $record->jobs()->open()->where('scheduled_at', '>=', now()->subHours(12))->orderBy('scheduled_at')->with('booking')->first();

                        return $job ? $job->scheduled_at->format('d M, H:i').' · '.$job->typeLabel().' · '.$job->booking?->reference : null;
                    })
                    ->placeholder('Free'),
                Tables\Columns\TextColumn::make('papers')
                    ->label('Documents')
                    ->state(function (User $record): string {
                        $problems = DriverPapers::problems($record);
                        $warnings = DriverPapers::warnings($record);

                        return $problems ? count($problems).' to sort' : ($warnings ? 'Expiring soon' : 'OK');
                    })
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        $state === 'OK' => 'success',
                        $state === 'Expiring soon' => 'warning',
                        default => 'danger',
                    })
                    ->tooltip(fn (User $record): ?string => implode(' · ', [...DriverPapers::problems($record), ...DriverPapers::warnings($record)]) ?: null)
                    ->visible(fn (): bool => Access::allows('manage-drivers')),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Login')
                    ->state(fn (User $record): string => match (true) {
                        ! $record->login_enabled => 'Off',
                        (bool) $record->last_login_at => $record->last_login_at->diffForHumans(),
                        (bool) $record->invited_at => 'Invited',
                        default => 'Not set up',
                    })
                    ->color(fn (User $record): string => $record->login_enabled ? 'gray' : 'danger')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['assignedCars', 'driverProfile']))
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
            ->emptyStateDescription('Add your drivers here, then pick them on car bookings.');
    }

    public static function getRelations(): array
    {
        return [
            DriverResource\RelationManagers\DocumentsRelationManager::class,
            DriverResource\RelationManagers\JobsRelationManager::class,
            DriverResource\RelationManagers\UnavailabilityRelationManager::class,
            \App\Filament\RelationManagers\EmailsRelationManager::class,
            DriverResource\RelationManagers\DriverBookingsRelationManager::class,
            \App\Filament\RelationManagers\HistoryRelationManager::class,
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
