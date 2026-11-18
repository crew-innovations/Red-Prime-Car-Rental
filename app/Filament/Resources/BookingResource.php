<?php

namespace App\Filament\Resources;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\DriveMode;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ServiceType;
use App\Enums\YachtStatus;
use App\Filament\Resources\BookingResource\BookingActions;
use App\Filament\Resources\BookingResource\Pages;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Trip;
use App\Models\TripDate;
use App\Models\Yacht;
use App\Support\Availability;
use Closure;
use App\Filament\Concerns\GuardedResource;
use App\Support\Access;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/** Booking Management (Schedule A7) with delivery and return (A10). */
class BookingResource extends Resource
{
    use GuardedResource;

    protected static function manageAbility(): string
    {
        return 'manage-bookings';
    }

    protected static function deleteAbility(): string
    {
        return 'delete-records';
    }

    /** Drivers work from "My jobs" (no prices or payments there), not from the bookings list. */
    public static function canViewAny(): bool
    {
        return ! Access::isDriverOnly();
    }

    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Bookings & drivers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference';

    /* ------------------------------------------------------------------ */
    /*  Form                                                              */
    /* ------------------------------------------------------------------ */

    public static function form(Form $form): Form
    {
        return $form
            ->disabled(fn (): bool => ! static::canModify())
            ->columns(3)
            ->schema([
                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        self::serviceSection(),
                        \App\Filament\Concerns\TransferFields::section(),
                        self::datesSection(),
                        self::priceSection(),
                        self::photosSection(),
                    ]),
                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        self::statusSection(),
                        Forms\Components\Section::make('Progress')
                            ->icon('heroicon-o-map')
                            ->collapsible()
                            ->visibleOn('edit')
                            ->schema([
                                Forms\Components\Placeholder::make('journey')
                                    ->hiddenLabel()
                                    ->content(fn (?Booking $record): HtmlString => new HtmlString($record ? \App\Support\Sales\Journey::html(\App\Support\Sales\Journey::forBooking($record)) : '')),
                            ]),
                        self::paymentSection(),
                        self::supplierSection(),
                        self::operationsSection(),
                        self::documentsSection(),
                        Forms\Components\Section::make('Team & notes')
                            ->icon('heroicon-o-user-group')
                            ->schema([
                                Forms\Components\Select::make('assigned_to')
                                    ->label('Salesperson')
                                    ->relationship('assignee', 'name', fn (Builder $query) => $query->where('is_active', true))
                                    ->default(fn () => auth()->id())
                                    ->searchable()
                                    ->preload(),
                                Forms\Components\Textarea::make('notes')
                                    ->label('Internal notes')
                                    ->rows(3),
                            ]),
                    ]),
            ]);
    }

    /** A manager booked a car / yacht that is in maintenance or unavailable: keep a record. */
    public static function logOverride(Booking $booking, array $data): void
    {
        if (empty($data['override_availability']) || ! in_array($booking->service_type?->value, ['car', 'yacht'], true)) {
            return;
        }
        if ($message = Availability::restriction($booking->service_type->value, (int) $booking->bookable_id)) {
            \App\Models\AuditLog::note($booking, 'override', 'Booked anyway: '.$message);
        }
    }

    /** Required documents for this booking (rules from Business settings). */
    protected static function documentsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Documents')
            ->icon('heroicon-o-identification')
            ->visibleOn('edit')
            ->collapsible()
            ->schema([
                Forms\Components\Placeholder::make('documents_checklist')
                    ->hiddenLabel()
                    ->content(function (?Booking $record): HtmlString {
                        if (! $record) {
                            return new HtmlString('');
                        }
                        $rows = $record->documentChecklist();
                        if (! $rows) {
                            return new HtmlString('<span style="opacity:.7">No required documents set for this type of booking. Set them in Settings › Business settings.</span>');
                        }

                        $html = collect($rows)->map(fn ($row) => '<div style="display:flex;justify-content:space-between;gap:.5rem"><span>'.e($row[0]).'</span><span style="font-weight:600;color:rgb(var(--'.($row[2] ? 'success' : ($row[1] === 'Missing' ? 'danger' : 'warning')).'-500))">'.e($row[1]).'</span></div>')->implode('');
                        if (\App\Models\Setting::read('doc_block_handover') && collect($rows)->contains(fn ($r) => ! $r[2])) {
                            $html .= '<div style="margin-top:.5rem;font-size:.8rem;opacity:.75">Handover is blocked until these are approved (a manager can override).</div>';
                        }

                        return new HtmlString($html.'<div style="margin-top:.5rem;font-size:.8rem;opacity:.7">Upload, review or request files on the customer page › Documents.</div>');
                    }),
            ]);
    }

    protected static function serviceSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Customer & service')
            ->icon('heroicon-o-user')
            ->columns(2)
            ->schema([
                Forms\Components\Select::make('customer_id')
                    ->label('Customer')
                    ->relationship('customer', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => $record->name.($record->phone ? ' · '.$record->phone : ''))
                    ->searchable(['name', 'phone', 'whatsapp', 'email', 'company_name'])
                    ->preload()
                    ->required(fn (Get $get): bool => blank($get('lead_id')))
                    ->placeholder(fn (Get $get): string => filled($get('lead_id')) ? 'Created from the lead when saved' : 'Select an option')
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('phone')->tel()->required()->maxLength(30),
                        Forms\Components\TextInput::make('email')->email()->maxLength(255),
                    ])
                    ->columnSpanFull(),
                Forms\Components\Hidden::make('lead_id'),

                Forms\Components\ToggleButtons::make('service_type')
                    ->label('Service')
                    ->options(fn (?Booking $record): array => ServiceType::pickerOptions($record?->service_type))
                    ->icons(ServiceType::pickerIcons())
                    ->default(ServiceType::Car->value)
                    ->inline()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('bookable_id', null);
                        $set('trip_date_id', null);
                        $set('package_departure_id', null);
                    })
                    ->columnSpanFull(),

                Forms\Components\Select::make('bookable_id')
                    ->label(fn (Get $get): string => self::itemLabel($get('service_type')))
                    ->options(fn (Get $get): array => self::itemOptions($get('service_type'), $get('vehicle_category_id')))
                    ->searchable()
                    ->required(fn (Get $get): bool => $get('service_type') !== ServiceType::Transfer->value)
                    ->placeholder(fn (Get $get): string => $get('service_type') === ServiceType::Transfer->value ? 'Assign a car later' : 'Select an option')
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $item = self::findItem($get('service_type'), $get('bookable_id'));
                        if ($item instanceof Car) {
                            $set('drive_mode', $item->self_drive ? DriveMode::SelfDrive->value : DriveMode::WithDriver->value);
                            self::suggestDriver($get, $set);
                        }
                        if (($item instanceof Car || $item instanceof Yacht) && $item->deposit) {
                            $set('security_deposit', (float) $item->deposit);
                        }
                        $set('trip_date_id', null);
                        $set('package_departure_id', null);
                        if ($item instanceof \App\Models\Package && $item->meeting_point) {
                            $set('pickup_location', $item->meeting_point);
                        }
                        self::recalculate($get, $set);
                    })
                    ->helperText(fn (Get $get): ?HtmlString => self::itemHint($get))
                    ->columnSpanFull(),

                // Cross-hired vehicle (29 Oct): which supplier it comes from this time.
                Forms\Components\Select::make('supplier_id')
                    ->label('Hired from (supplier)')
                    ->options(fn () => \App\Models\Supplier::query()->whereIn('status', ['active', 'pending'])->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (Get $get): bool => ($v = self::findItem($get('service_type') === ServiceType::Transfer->value ? ServiceType::Car->value : $get('service_type'), $get('bookable_id'))) instanceof Model && ($v->ownership ?? null) === 'cross_hire')
                    ->required(fn (Get $get): bool => ($v = self::findItem($get('service_type') === ServiceType::Transfer->value ? ServiceType::Car->value : $get('service_type'), $get('bookable_id'))) instanceof Model && ($v->ownership ?? null) === 'cross_hire' && ! $v->supplier_id)
                    ->default(fn (Get $get) => self::findItem($get('service_type'), $get('bookable_id'))?->supplier_id)
                    ->helperText('Its cost comes from this supplier\'s cost rates.')
                    ->columnSpanFull(),

                Forms\Components\ToggleButtons::make('drive_mode')
                    ->label('Rental type')
                    ->options(DriveMode::class)
                    ->default(DriveMode::SelfDrive->value)
                    ->inline()
                    ->required(fn (Get $get): bool => $get('service_type') === ServiceType::Car->value)
                    ->disableOptionWhen(function (string $value, Get $get): bool {
                        $car = self::findItem(ServiceType::Car->value, $get('bookable_id'));

                        return $car instanceof Car && (
                            ($value === DriveMode::SelfDrive->value && ! $car->self_drive)
                            || ($value === DriveMode::WithDriver->value && ! $car->with_driver)
                        );
                    })
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        self::recalculate($get, $set);
                        self::suggestDriver($get, $set);
                    })
                    ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Car->value),

                Forms\Components\Select::make('driver_id')
                    ->label(fn (Get $get): string => $get('service_type') === ServiceType::Transfer->value ? 'Chauffeur' : ($get('drive_mode') === DriveMode::WithDriver->value ? 'Driver' : 'Delivery driver'))
                    ->options(fn (Get $get, ?Booking $record): array => BookingActions::driverOptions(
                        $get('bookable_id') ? (int) $get('bookable_id') : null,
                        $get('start_at'), $get('end_at'), $get('service_type') === ServiceType::Transfer->value ? DriveMode::WithDriver->value : $get('drive_mode'), $record?->getKey(),
                    ))
                    ->disableOptionWhen(fn (string $label): bool => str_contains($label, BookingActions::BUSY))
                    ->searchable()
                    ->live()
                    ->placeholder('Assign later')
                    ->helperText(fn (Get $get): ?string => BookingActions::driverOptions()
                        ? ($get('service_type') === ServiceType::Transfer->value ? 'Drives the customer from pickup to drop-off.' : ($get('drive_mode') === DriveMode::WithDriver->value
                            ? 'Drives the customer for the whole booking.'
                            : 'Delivers the car at the start and collects it at the end. Leave empty if the customer picks up and returns it at the office.')).' '.self::driverHint($get)
                        : 'Add drivers under Operations › Drivers.')
                    ->rules([fn (Get $get, ?Booking $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                        if (! $value || ! $get('start_at') || ! $get('end_at') || ! in_array($get('status'), array_map(fn ($s) => $s->value, BookingStatus::blocking()), true)) {
                            return;
                        }
                        $clash = Availability::personConflict((int) $value, $get('start_at'), $get('end_at'), $record?->getKey(), $get('service_type') === ServiceType::Transfer->value ? DriveMode::WithDriver->value : $get('drive_mode'));
                        if ($clash) {
                            $fail("This driver is already busy on {$clash->reference} (".$clash->start_at->format('d M H:i').' – '.$clash->end_at->format('d M H:i').').');
                        }
                    }])
                    ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Transfer->value || ($get('service_type') === ServiceType::Car->value
                        && ($get('drive_mode') === DriveMode::WithDriver->value || $get('delivery_method') !== 'office' || filled($get('driver_id'))))),
                Forms\Components\Select::make('return_driver_id')
                    ->label('Collection driver')
                    ->options(fn (Get $get, ?Booking $record): array => BookingActions::driverOptions(
                        $get('bookable_id') ? (int) $get('bookable_id') : null,
                        $get('end_at'), $get('end_at'), DriveMode::SelfDrive->value, $record?->getKey(),
                    ))
                    ->disableOptionWhen(fn (string $label): bool => str_contains($label, BookingActions::BUSY))
                    ->searchable()
                    ->placeholder(fn (Get $get): string => filled($get('driver_id')) ? 'Same as the delivery driver' : 'Pick who collects the car')
                    ->helperText('Change it if the delivery driver is not free at the return time.')
                    ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Car->value
                        && $get('drive_mode') !== DriveMode::WithDriver->value && $get('return_method') === 'collection'),

                // Private yacht charters (28 Oct): captain and crew from Drivers & crew.
                Forms\Components\ToggleButtons::make('crew_mode')
                    ->label('Crew')
                    ->options(['with_crew' => 'With captain & crew', 'bareboat' => 'Without crew (bareboat)'])
                    ->icons(['with_crew' => 'heroicon-m-user-group', 'bareboat' => 'heroicon-m-key'])
                    ->default('with_crew')
                    ->inline()
                    ->live()
                    ->disableOptionWhen(fn (string $value, Get $get): bool => $value === 'bareboat' && ! (self::findItem(ServiceType::Yacht->value, $get('bookable_id'))?->bareboat_allowed))
                    ->helperText('Bareboat only where the yacht is set up for it (licensed customer, allowed by the owner).')
                    ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Yacht->value)
                    ->columnSpanFull(),
                Forms\Components\Select::make('captain_id')
                    ->label('Captain')
                    ->options(fn (Get $get, ?Booking $record): array => BookingActions::crewOptions('captain', $get('start_at'), $get('end_at'), $record?->getKey()))
                    ->disableOptionWhen(fn (string $label): bool => str_contains($label, BookingActions::BUSY))
                    ->searchable()
                    ->placeholder('Assign later')
                    ->helperText(fn (): ?string => BookingActions::crewOptions('captain') ? null : 'Mark people as Captain under Operations › Drivers & crew.')
                    ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Yacht->value && $get('crew_mode') !== 'bareboat'),
                Forms\Components\Select::make('crew_ids')
                    ->label('Crew')
                    ->multiple()
                    ->options(fn (Get $get, ?Booking $record): array => collect(BookingActions::crewOptions('crew', $get('start_at'), $get('end_at'), $record?->getKey()))->except([(int) $get('captain_id')])->all())
                    ->disableOptionWhen(fn (string $label): bool => str_contains($label, BookingActions::BUSY))
                    ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Yacht->value && $get('crew_mode') !== 'bareboat'),

                Forms\Components\Textarea::make('driver_instructions')
                    ->label(fn (Get $get): string => $get('service_type') === ServiceType::Yacht->value ? 'Instructions for the crew' : 'Instructions for the driver')
                    ->placeholder('Gate code, parking, who to call, child seat…')
                    ->rows(2)
                    ->visible(fn (Get $get): bool => (in_array($get('service_type'), [ServiceType::Car->value, ServiceType::Transfer->value], true) && filled($get('driver_id')))
                        || ($get('service_type') === ServiceType::Yacht->value && filled($get('captain_id'))))
                    ->columnSpanFull(),

                Forms\Components\Select::make('trip_date_id')
                    ->label('Trip date')
                    ->options(fn (Get $get, ?Booking $record): array => self::tripDateOptions($get('bookable_id'), $record))
                    ->required(fn (Get $get): bool => $get('service_type') === ServiceType::Trip->value)
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $date = TripDate::with('trip')->find($get('trip_date_id'));
                        if ($date) {
                            $start = Carbon::parse($date->date->toDateString().' '.($date->start_time ?? $date->trip?->start_time ?? '09:00'));
                            $set('start_at', $start->toDateTimeString());
                            $set('end_at', $start->copy()->addMinutes($date->trip?->duration_minutes ?: \App\Support\Rules::tripMinutes())->toDateTimeString());
                            $set('pickup_location', $date->trip?->meeting_point ?: $date->trip?->departure_marina);
                        }
                    })
                    ->helperText(fn (Get $get): ?string => $get('bookable_id') && ! self::tripDateOptions($get('bookable_id'))
                        ? 'No upcoming dates. Add dates on the trip first.'
                        : null)
                    ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Trip->value)
                    ->columnSpanFull(),

                self::packageDepartureField(),
            ]);
    }

    /** Departure picker for Trips & Packages (shared with quotations). */
    public static function packageDepartureField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('package_departure_id')
            ->label('Departure')
            ->options(fn (Get $get, ?Model $record): array => self::packageDepartureOptions($get('bookable_id'), $record?->package_departure_id, $record instanceof Booking ? $record->getKey() : null))
            ->required(fn (Get $get): bool => $get('service_type') === ServiceType::Package->value
                && \App\Models\Package::find($get('bookable_id'))?->usesDepartures())
            ->live()
            ->afterStateUpdated(function (Get $get, Set $set) {
                $departure = \App\Models\PackageDeparture::with('package')->find($get('package_departure_id'));
                if ($departure) {
                    $set('start_at', $departure->startsAt()->toDateTimeString());
                    $set('end_at', $departure->endsAt()->toDateTimeString());
                }
                self::recalculate($get, $set);
            })
            ->helperText(fn (Get $get): ?string => match (true) {
                ! $get('bookable_id') => null,
                ! \App\Models\Package::find($get('bookable_id'))?->usesDepartures() => 'This package runs on request: set the dates below.',
                ! self::packageDepartureOptions($get('bookable_id')) => 'No upcoming departures. Add them on the package first.',
                default => null,
            })
            ->visible(fn (Get $get): bool => $get('service_type') === ServiceType::Package->value)
            ->columnSpanFull();
    }

    public static function packageDepartureOptions($packageId, ?int $currentId = null, ?int $ignoreBookingId = null): array
    {
        if (! $packageId) {
            return [];
        }

        return \App\Models\PackageDeparture::with('package')
            ->where('package_id', $packageId)
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('start_date', '>=', today())->where('is_available', true))
                ->when($currentId, fn (Builder $q, $id) => $q->orWhere('id', $id)))
            ->orderBy('start_date')
            ->limit(60)
            ->get()
            ->mapWithKeys(fn (\App\Models\PackageDeparture $d) => [$d->id => $d->label($ignoreBookingId)])
            ->all();
    }

    public static function itemLabel(?string $service): string
    {
        return match ($service) {
            ServiceType::Yacht->value => 'Yacht (old charter)',
            ServiceType::Trip->value => 'Yacht trip',
            ServiceType::Package->value => 'Trip / package',
            ServiceType::Transfer->value => 'Car (optional)',
            default => 'Car',
        };
    }

    protected static function datesSection(): Forms\Components\Section
    {
        $isCar = fn (Get $get): bool => $get('service_type') === ServiceType::Car->value;
        $isTransfer = fn (Get $get): bool => $get('service_type') === ServiceType::Transfer->value;
        $hourly = fn (Get $get): bool => $isTransfer($get) && \App\Models\TransferService::find($get('transfer_service_id'))?->isHourly();

        return Forms\Components\Section::make('Dates & place')
            ->icon('heroicon-o-calendar')
            ->columns(2)
            ->schema([
                Forms\Components\DateTimePicker::make('start_at')
                    ->label(fn (Get $get): string => $isCar($get) || $isTransfer($get) ? 'Pick-up' : 'Start')
                    ->native(false)
                    ->seconds(false)
                    ->minutesStep(15)
                    ->displayFormat('D, d M Y · H:i')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($isCar) {
                        if ($state && ! $get('end_at')) {
                            $set('end_at', self::defaultEnd($get('service_type'), $get('bookable_id'), $state));
                        }
                        self::recalculate($get, $set);
                    }),
                Forms\Components\DateTimePicker::make('end_at')
                    ->label(fn (Get $get): string => $isCar($get) ? 'Return' : ($isTransfer($get) ? 'Hire ends' : 'End'))
                    ->native(false)
                    ->seconds(false)
                    ->minutesStep(15)
                    ->displayFormat('D, d M Y · H:i')
                    ->required(fn (Get $get): bool => ! $isTransfer($get) || $hourly($get))
                    ->after('start_at')
                    ->visible(fn (Get $get): bool => ! $isTransfer($get) || $hourly($get))
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set))
                    ->helperText(fn (Get $get): ?string => self::durationText($get('start_at'), $get('end_at')))
                    ->rules([fn (Get $get, ?Booking $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                        $type = $get('service_type') === ServiceType::Transfer->value ? ServiceType::Car->value : $get('service_type');
                        if (! in_array($type, [ServiceType::Car->value, ServiceType::Yacht->value], true) || ! $get('bookable_id') || ! $get('start_at')
                            || ! in_array($get('status'), array_map(fn ($s) => $s->value, BookingStatus::blocking()), true)) {
                            return;
                        }
                        // Runs inside the save transaction with the car / yacht row locked.
                        if ($message = Availability::assetConflict($type, (int) $get('bookable_id'), $get('start_at'), $value, $record?->getKey())) {
                            $fail($message);

                            return;
                        }
                        $unchanged = $record && (int) $record->bookable_id === (int) $get('bookable_id')
                            && $record->start_at?->equalTo(Carbon::parse($get('start_at'))) && $record->end_at?->equalTo(Carbon::parse($value));
                        if (! $unchanged && ($message = Availability::restriction($type, (int) $get('bookable_id'))) && ! ($get('override_availability') && Access::allows('override-availability'))) {
                            $fail($message.(Access::allows('override-availability') ? ' Tick "Book anyway" to override.' : ' A manager can override this.'));
                        }
                    }]),
                Forms\Components\Toggle::make('override_availability')
                    ->label('Book anyway (manager override, recorded in history)')
                    ->dehydrated(false)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => Access::allows('override-availability')
                        && in_array($get('service_type'), [ServiceType::Car->value, ServiceType::Yacht->value], true)
                        && $get('bookable_id')
                        && Availability::restriction($get('service_type'), (int) $get('bookable_id')) !== null),
                Forms\Components\TextInput::make('pickup_location')
                    ->label(fn (Get $get): string => $isTransfer($get) ? 'Pickup address' : ($isCar($get) ? ($get('delivery_method') === 'delivery' ? 'Delivery address' : 'Pick-up location') : 'Meeting point / marina'))
                    ->required(fn (Get $get): bool => ($isCar($get) && $get('delivery_method') === 'delivery') || $isTransfer($get))
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => $isTransfer($get) ? self::recalculate($get, $set) : null)
                    ->placeholder(fn (Get $get): string => $isCar($get) ? 'Office, hotel, airport…' : 'Dubai Marina')
                    ->datalist(fn (): array => \App\Support\Options::list('pickup_locations'))
                    ->maxLength(255),
                Forms\Components\TextInput::make('return_location')
                    ->label('Return location')
                    ->placeholder('Same as pick-up')
                    ->datalist(fn (): array => \App\Support\Options::list('pickup_locations'))
                    ->maxLength(255)
                    ->visible($isCar),
                ...\App\Filament\Concerns\PriceFields::handoverChoice(),
                Forms\Components\TextInput::make('guests')
                    ->label(fn (Get $get): string => $isTransfer($get) ? 'Passengers' : 'Guests')
                    ->numeric()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => in_array($get('service_type'), [ServiceType::Trip->value, ServiceType::Package->value], true))
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set))
                    ->rules([fn (Get $get, ?Booking $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                        if ($get('service_type') === ServiceType::Package->value) {
                            if ($message = self::packageGuestProblem($get('bookable_id'), $get('package_departure_id'), (int) $value, $record?->getKey())) {
                                $fail($message);
                            }

                            return;
                        }
                        if ($get('service_type') === ServiceType::Trip->value && ($date = TripDate::with('trip')->find($get('trip_date_id')))) {
                            $left = Availability::seatsLeft($date, $record?->getKey()); // locks the date row
                            if ($left !== null && (int) $value > $left) {
                                $fail("Only {$left} seat(s) left on this date.");
                            }
                        }
                        if ($get('service_type') === ServiceType::Yacht->value && ($yacht = Yacht::find($get('bookable_id'))) && $yacht->max_guests && (int) $value > $yacht->max_guests) {
                            $fail("This yacht takes up to {$yacht->max_guests} guests.");
                        }
                    }])
                    ->visible(fn (Get $get): bool => ! $isCar($get)),
            ]);
    }

    /** Advance required / paid / remaining with a bar (4 Nov, 11). */
    public static function advanceProgress(?Booking $record): HtmlString
    {
        $required = (float) $record?->advance_required;
        if (! $record || $required <= 0) {
            return new HtmlString('');
        }
        $paid = (float) $record->paid_amount;
        $left = max(0, round($required - $paid, 2));
        $pct = (int) min(100, round($paid / $required * 100));
        $color = $left <= 0 ? 'success' : 'warning';
        $due = \App\Support\Pricing\Advance::balanceDue($record);

        return new HtmlString(
            '<div style="font-size:.9rem"><b>AED '.number_format($required, 2).'</b>'
            .($record->advance_percent !== null ? ' ('.\App\Support\Pricing\Discounts::percentText((float) $record->advance_percent).')' : '')
            .' · paid AED '.number_format(min($paid, $required), 2)
            .' · '.($left > 0 ? '<span style="color:rgb(var(--warning-600));font-weight:600">AED '.number_format($left, 2).' still to pay</span>' : '<span style="color:rgb(var(--success-600));font-weight:600">reached'.($record->advance_reached_at ? ' '.$record->advance_reached_at->format('d M H:i') : '').'</span>')
            .'</div><div style="height:8px;border-radius:6px;background:rgba(127,127,127,.2);margin-top:6px;overflow:hidden"><div style="height:8px;width:'.$pct.'%;background:rgb(var(--'.$color.'-500))"></div></div>'
            .((float) $record->balance > 0 && $due ? '<div style="font-size:.8rem;opacity:.75;margin-top:4px">Balance AED '.number_format((float) $record->balance, 2).' due '.$due->format('d M Y').'</div>' : '')
        );
    }

    protected static function priceSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Price')
            ->icon('heroicon-o-banknotes')
            ->description(fn (?Booking $record): string => $record?->quotation
                ? 'Agreed in quotation '.$record->quotation->number.' at AED '.number_format((float) $record->quotation->total, 2).'. Any change needs a reason and is saved in the history.'
                : 'From the price list. Changes are saved in the history; bigger discounts and other changes need a reason.')
            ->columns(3)
            ->schema([
                ...\App\Filament\Concerns\PriceFields::schema('booking'),
                Forms\Components\TextInput::make('security_deposit')
                    ->label('Security deposit')
                    ->helperText('Refundable, collected at handover. Not revenue.')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('AED')
                    ->default(0),
                ...\App\Filament\Concerns\PriceFields::advance('booking'),
                Forms\Components\Placeholder::make('advance_progress')
                    ->label('Advance')
                    ->hiddenOn('create')
                    ->visible(fn (?Booking $record): bool => (float) $record?->advance_required > 0)
                    ->content(fn (?Booking $record): HtmlString => self::advanceProgress($record)),
                Forms\Components\Group::make()
                    ->visibleOn('create')
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        Forms\Components\TextInput::make('advance_amount')
                            ->label('Advance paid now')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->dehydrated(false),
                        Forms\Components\Select::make('advance_method')
                            ->label('Paid by')
                            ->options(PaymentMethod::class)
                            ->default(PaymentMethod::Card->value)
                            ->native(false)
                            ->dehydrated(false),
                    ]),
            ]);
    }

    protected static function photosSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Photos & documents')
            ->icon('heroicon-o-camera')
            ->collapsible()
            ->collapsed(fn (?Booking $record): bool => ! $record?->delivered_at)
            ->hiddenOn('create')
            ->columns(3)
            ->schema([
                Forms\Components\SpatieMediaLibraryFileUpload::make('delivery_photos')
                    ->label('At handover')
                    ->collection('delivery')->disk('private')->visibility('private')
                    ->image()->multiple()->panelLayout('grid')->openable()->downloadable(),
                Forms\Components\SpatieMediaLibraryFileUpload::make('return_photos')
                    ->label('At return')
                    ->collection('return')->disk('private')->visibility('private')
                    ->image()->multiple()->panelLayout('grid')->openable()->downloadable(),
                Forms\Components\SpatieMediaLibraryFileUpload::make('documents')
                    ->label('Signed agreement & other files')
                    ->collection('documents')->disk('private')->visibility('private')
                    ->multiple()->openable()->downloadable()
                    ->acceptedFileTypes(['image/*', 'application/pdf']),
                // 4 Nov (15): signed by the customer on the driver's phone. Read-only here.
                Forms\Components\SpatieMediaLibraryFileUpload::make('signatures')
                    ->label('Customer signatures (driver\'s phone)')
                    ->collection('signatures')->disk('private')->visibility('private')
                    ->image()->multiple()->panelLayout('grid')->openable()->downloadable()->disabled()->dehydrated(false)
                    ->visible(fn (?Booking $record): bool => (bool) $record?->getMedia('signatures')->isNotEmpty()),
            ]);
    }

    protected static function statusSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Status')
            ->icon('heroicon-o-signal')
            ->schema([
                Forms\Components\ToggleButtons::make('status')
                    ->hiddenLabel()
                    ->options(fn (?Booking $record): array => collect(in_array($record?->status, [BookingStatus::InProgress, BookingStatus::Completed], true)
                        ? [$record->status]
                        : BookingStatus::manual())->mapWithKeys(fn (BookingStatus $s) => [$s->value => $s->getLabel()])->all())
                    ->colors(collect(BookingStatus::cases())->mapWithKeys(fn (BookingStatus $s) => [$s->value => $s->getColor()])->all())
                    ->icons(collect(BookingStatus::cases())->mapWithKeys(fn (BookingStatus $s) => [$s->value => $s->getIcon()])->all())
                    ->default(BookingStatus::Confirmed->value)
                    ->required()
                    ->live()
                    ->columns(2),
                Forms\Components\Placeholder::make('website_hold')
                    ->hiddenLabel()
                    ->visible(fn (?Booking $record): bool => $record?->source === 'website' && $record->hold_until !== null)
                    ->content(fn (?Booking $record) => new \Illuminate\Support\HtmlString('<span style="color:#d97706;font-weight:600">'.e($record->holdExpired()
                        ? 'Website booking: not paid in time. It no longer holds the dates and will be cancelled.'
                        : 'Website booking: waiting for payment until '.$record->hold_until->timezone(config('app.timezone'))->format('j M, H:i').'. Send the payment link (Payment section); after that time it is cancelled.').'</span>')),
                Forms\Components\Placeholder::make('status_hint')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => match ($get('status')) {
                        BookingStatus::Confirmed->value, BookingStatus::PendingPayment->value => 'The car / yacht is held for these dates.',
                        BookingStatus::InProgress->value => 'Out with the customer. Use "Receive" when it comes back.',
                        BookingStatus::Completed->value => 'Returned and closed.',
                        default => 'Not holding the car / yacht yet.',
                    }),
                Forms\Components\TextInput::make('cancel_reason')
                    ->label('Reason')
                    ->visible(fn (Get $get): bool => in_array($get('status'), [BookingStatus::Cancelled->value, BookingStatus::NoShow->value], true))
                    ->required(fn (Get $get): bool => in_array($get('status'), [BookingStatus::Cancelled->value, BookingStatus::NoShow->value], true)),
            ]);
    }

    protected static function paymentSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Payment')
            ->icon('heroicon-o-credit-card')
            ->hiddenOn('create')
            ->schema([
                Forms\Components\Placeholder::make('payment_summary')
                    ->hiddenLabel()
                    ->content(function (?Booking $record): HtmlString {
                        if (! $record) {
                            return new HtmlString('');
                        }
                        $row = fn (string $a, string $b, string $style = '') => "<tr><td style=\"padding:.2rem 0;opacity:.8\">{$a}</td><td style=\"text-align:right;{$style}\">{$b}</td></tr>";
                        $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
                        $html = '<table style="width:100%;font-size:.875rem">'
                            .$row('Booking total', $aed($record->total))
                            .((float) $record->extra_charges > 0 ? $row('Return charges', $aed($record->extra_charges)) : '')
                            .$row('Received', $aed($record->paid_amount), 'color:rgb(var(--success-500))')
                            .$row('<b>Balance</b>', '<b>'.$aed($record->balance).'</b>', (float) $record->balance > 0 ? 'color:rgb(var(--danger-500))' : '')
                            .$row('Deposit held', $aed($record->depositHeld()))
                            .'</table>';

                        return new HtmlString($html);
                    }),
                Forms\Components\Placeholder::make('payment_status_badge')
                    ->hiddenLabel()
                    ->content(fn (?Booking $record): HtmlString => new HtmlString($record?->payment_status
                        ? '<span style="font-weight:600;color:rgb(var(--'.$record->payment_status->getColor().'-500))">'.$record->payment_status->getLabel().'</span> · see the Payments tab below'
                        : 'No payment due yet')),
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('balanceHistory')
                        ->label('Why is the balance this?')
                        ->icon('heroicon-o-clock')
                        ->link()
                        ->modalHeading('Balance history')
                        ->modalDescription('Price changes, return charges, payments and corrections, oldest first.')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalContent(fn (?Booking $record): HtmlString => new HtmlString($record ? \App\Support\Accounts\BalanceHistory::html($record) : '')),
                ]),
            ]);
    }

    /** Supplier cost and profit (29 Oct). The warning is for everyone; figures for Finance only. */
    protected static function supplierSection(): Forms\Components\Section
    {
        $aed = fn ($v) => $v === null ? '—' : 'AED '.number_format((float) $v, 2);

        return Forms\Components\Section::make('Supplier & profit')
            ->icon('heroicon-o-building-storefront')
            ->hiddenOn('create')
            ->collapsible()
            ->visible(fn (?Booking $record): bool => $record && ($record->supplier_cost_status || \App\Support\Access::allows('manage-finance')))
            ->schema([
                Forms\Components\Placeholder::make('supplier_state')
                    ->hiddenLabel()
                    ->visible(fn (?Booking $record): bool => (bool) $record?->supplier_cost_status)
                    ->content(function (?Booking $record) use ($aed): HtmlString {
                        $s = (array) $record->supplier_cost_snapshot;
                        $who = e(($s['supplier'] ?? 'Supplier').' · '.($s['vehicle'] ?? ''));
                        $finance = \App\Support\Access::allows('manage-finance');

                        return new HtmlString(match ($record->supplier_cost_status) {
                            'missing' => '<p style="color:rgb(var(--danger-600));font-weight:600">Supplier cost not set</p><p style="font-size:.85rem">'.e($s['reason'] ?? '').'</p>',
                            'pending' => '<p style="font-weight:600">'.$who.'</p><p style="font-size:.85rem;opacity:.8">'.e($s['reason'] ?? '').'</p>',
                            'override' => '<p style="font-weight:600">'.$who.'</p>'.($finance ? '<p style="font-size:.85rem">Cost set by hand: '.$aed($record->supplier_cost).' · '.e(data_get($s, 'override.reason')).' ('.e(data_get($s, 'override.by_name')).')</p>' : ''),
                            default => '<p style="font-weight:600">'.$who.'</p>'.($finance ? '<p style="font-size:.85rem;opacity:.85">'.e(\App\Support\Suppliers\SupplierLedger::explain($s)).' = '.$aed($record->supplier_cost).'</p>' : ''),
                        });
                    }),
                Forms\Components\Placeholder::make('profit')
                    ->hiddenLabel()
                    ->visible(fn (): bool => \App\Support\Access::allows('manage-finance'))
                    ->content(function (?Booking $record) use ($aed): HtmlString {
                        $p = \App\Support\Suppliers\BookingProfit::for($record);
                        $row = fn (string $a, string $b, string $style = '') => "<tr><td style=\"padding:.2rem 0;opacity:.8\">{$a}</td><td style=\"text-align:right;{$style}\">{$b}</td></tr>";
                        $pct = fn ($m) => $m === null ? '' : ' · '.rtrim(rtrim(number_format($m, 1), '0'), '.').'%';

                        return new HtmlString('<table style="width:100%;font-size:.875rem">'
                            .$row('Revenue (no VAT)', $aed($p['revenue']))
                            .($record->supplier_id ? $row('Supplier'.($p['supplier_posted'] ? ' (bill posted'.($p['supplier_paid'] ? ', '.e(strtolower($p['supplier_paid'])) : '').')' : ' (estimate)'), $p['supplier'] === null ? '<span style="color:rgb(var(--danger-600))">not known</span>' : $aed($p['supplier'])) : '')
                            .$row('Other direct costs', $aed($p['other']))
                            .$row('<b>Estimated profit</b>', '<b>'.$aed($p['estimated']).'</b>'.$pct($p['estimated_margin']), ($p['estimated'] ?? 0) < 0 ? 'color:rgb(var(--danger-600))' : '')
                            .$row('Actual profit', $p['actual'] === null ? '<span style="opacity:.7">'.e($p['why_no_actual']).'</span>' : $aed($p['actual']).$pct($p['actual_margin']), ($p['actual'] ?? 0) < 0 ? 'color:rgb(var(--danger-600))' : '')
                            .'</table>');
                    }),
            ]);
    }

    protected static function operationsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Handover & return')
            ->icon('heroicon-o-key')
            ->visible(fn (?Booking $record): bool => (bool) $record?->delivered_at)
            ->schema([
                Forms\Components\Placeholder::make('ops')
                    ->hiddenLabel()
                    ->content(function (?Booking $record): HtmlString {
                        $line = fn (string $a, ?string $b) => $b ? '<div style="display:flex;justify-content:space-between;gap:1rem;font-size:.875rem;padding:.15rem 0"><span style="opacity:.75">'.$a.'</span><span style="text-align:right">'.e($b).'</span></div>' : '';
                        $fuel = fn ($f) => ($f === null || $f === '') ? null : \App\Support\Fuel\Fuel::label($f); // 5 Nov (S5): eighths
                        $html = '<div style="font-weight:600;margin-bottom:.25rem">Out</div>'
                            .$line('When', $record->delivered_at?->format('d M Y, H:i'))
                            .$line('By', $record->deliveredBy?->name)
                            .$line('Odometer', $record->delivery_km ? number_format($record->delivery_km).' km' : null)
                            .$line('Fuel', $fuel($record->delivery_fuel_8 ?? $record->delivery_fuel))
                            .$line('Notes', $record->delivery_notes);
                        if ($record->returned_at) {
                            $charges = collect($record->charges ?? [])->map(fn ($v, $k) => \App\Support\Options::chargeLabel($k).' AED '.number_format((float) $v))->implode(', ');
                            $html .= '<div style="font-weight:600;margin:.6rem 0 .25rem">Back</div>'
                                .$line('When', $record->returned_at->format('d M Y, H:i'))
                                .$line('By', $record->receivedBy?->name)
                                .$line('Odometer', $record->return_km ? number_format($record->return_km).' km'.($record->delivery_km ? ' ('.number_format($record->return_km - $record->delivery_km).' driven)' : '') : null)
                                .$line('Fuel', $fuel($record->return_fuel_8 ?? $record->return_fuel)).($record->fuel_charge_note ? $line('Fuel charge changed', $record->fuel_charge_note) : '')
                                .$line('Condition', Booking::CONDITIONS[$record->return_condition] ?? null)
                                .$line('Charges', $charges ?: null)
                                .$line('Notes', $record->return_notes);
                        }

                        return new HtmlString($html);
                    }),
            ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Form helpers                                                      */
    /* ------------------------------------------------------------------ */

    public static function findItem(?string $service, $id): ?Model
    {
        if (! $id) {
            return null;
        }

        return match ($service) {
            ServiceType::Car->value, ServiceType::Transfer->value => Car::withTrashed()->find($id),
            ServiceType::Yacht->value => Yacht::withTrashed()->find($id),
            ServiceType::Trip->value => Trip::withTrashed()->find($id),
            ServiceType::Package->value => \App\Models\Package::withTrashed()->find($id),
            default => null,
        };
    }

    /** End time filled in after picking the start: the package length, or the default from settings. */
    public static function defaultEnd(?string $service, $itemId, string $start): string
    {
        $start = Carbon::parse($start);
        if ($service === ServiceType::Package->value && ($package = \App\Models\Package::find($itemId)) && $package->duration_days) {
            return ($package->duration_days > 1 ? $start->copy()->addDays($package->duration_days - 1)->setTime(18, 0) : $start->copy()->addHours(8))->toDateTimeString();
        }

        return $start->copy()->addHours(\App\Support\Rules::defaultHours($service))->toDateTimeString();
    }

    /** Guests over the package limits or the places left on the departure (locks the departure row). */
    public static function packageGuestProblem($packageId, $departureId, int $guests, ?int $ignoreBookingId = null): ?string
    {
        $package = \App\Models\Package::find($packageId);
        if (! $package) {
            return null;
        }
        if ($package->min_guests && $guests < $package->min_guests) {
            return "This package needs at least {$package->min_guests} guests.";
        }
        if ($package->max_guests && $guests > $package->max_guests) {
            return "This package takes up to {$package->max_guests} guests per booking.";
        }
        if ($departureId && ($departure = \App\Models\PackageDeparture::with('package')->find($departureId))) {
            $left = Availability::packageSeatsLeft($departure, $ignoreBookingId);
            if ($left !== null && $guests > $left) {
                return "Only {$left} place(s) left on this departure.";
            }
        }

        return null;
    }

    public static function itemOptions(?string $service, $categoryId = null): array
    {
        return match ($service) {
            ServiceType::Transfer->value => Car::query()->whereNotNull('vehicle_category_id')->when($categoryId, fn ($q, $id) => $q->where('vehicle_category_id', $id))->orderBy('name')->get()
                ->mapWithKeys(fn (Car $c) => [$c->id => $c->name.' · '.$c->status?->getLabel()])->all(),
            ServiceType::Yacht->value => Yacht::orderBy('name')->get()
                ->mapWithKeys(fn (Yacht $y) => [$y->id => $y->name.' · '.$y->status?->getLabel().($y->hourly_price ? ' · AED '.number_format((float) $y->hourly_price).'/hr' : '')])->all(),
            ServiceType::Trip->value => Trip::orderBy('name')->get()
                ->mapWithKeys(fn (Trip $t) => [$t->id => $t->name.($t->price ? ' · AED '.number_format((float) $t->price) : '')])->all(),
            ServiceType::Package->value => \App\Models\Package::orderBy('name')->get()
                ->mapWithKeys(fn (\App\Models\Package $p) => [$p->id => $p->name.(($u = $p->unitPrice()) !== null ? ' · '.$p->currency.' '.number_format($u).($p->isPerPerson() ? '/person' : '') : '')])->all(),
            ServiceType::Car->value => Car::orderBy('name')->get()
                ->mapWithKeys(fn (Car $c) => [$c->id => $c->name.' · '.$c->status?->getLabel().($c->daily_price ? ' · AED '.number_format((float) $c->daily_price).'/day' : '')])->all(),
            default => [],
        };
    }

    protected static function itemHint(Get $get): ?HtmlString
    {
        $item = self::findItem($get('service_type'), $get('bookable_id'));
        if (! $item instanceof Car && ! $item instanceof Yacht) {
            return null;
        }

        $parts = [];
        if (in_array($item->status, [CarStatus::Maintenance, CarStatus::Unavailable, YachtStatus::Maintenance, YachtStatus::Unavailable], true)) {
            $parts[] = '<span style="color:rgb(var(--danger-500))">Currently '.$item->status->getLabel().'</span>';
        }
        $upcoming = $item->bookings()->blocking()->where('end_at', '>=', now())->orderBy('start_at')->limit(3)->get();
        if ($upcoming->isNotEmpty()) {
            $parts[] = 'Booked: '.$upcoming->map(fn (Booking $b) => $b->start_at->format('d M').'–'.$b->end_at->format('d M'))->implode(', ');
        }
        if ($item instanceof Car) {
            $modes = array_filter([$item->self_drive ? 'Self-drive' : null, $item->with_driver ? 'With driver' : null]);
            $parts[] = implode(' & ', $modes) ?: 'No rental type set';
        }

        return $parts ? new HtmlString(implode(' · ', $parts)) : null;
    }

    public static function tripDateOptions($tripId, ?Booking $record = null): array
    {
        if (! $tripId) {
            return [];
        }

        $trip = Trip::find($tripId);

        return TripDate::where('trip_id', $tripId)
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('date', '>=', today())->where('is_available', true))
                ->when($record?->trip_date_id, fn (Builder $q, $id) => $q->orWhere('id', $id)))
            ->orderBy('date')
            ->limit(60)
            ->get()
            ->mapWithKeys(function (TripDate $d) use ($trip, $record) {
                $seats = $d->available_slots ?? $trip?->guest_capacity;
                $left = $seats === null ? null : $seats - $d->bookedGuests($record?->getKey());
                $time = $d->start_time ?? $trip?->start_time;

                return [$d->id => $d->date->format('D, d M Y').($time ? ' · '.substr($time, 0, 5) : '').($left !== null ? " · {$left} seats left" : '')];
            })
            ->all();
    }

    /** Pick the car's usual driver when the booking is with driver and no driver is chosen yet. */
    public static function suggestDriver(Get $get, Set $set): void
    {
        if ($get('service_type') !== ServiceType::Car->value || $get('drive_mode') !== DriveMode::WithDriver->value || $get('driver_id')) {
            return;
        }

        $car = Car::find($get('bookable_id'));
        if (! $car) {
            return;
        }

        foreach ($car->drivers()->where('is_active', true)->get() as $driver) {
            if (! $get('start_at') || ! Availability::driverConflict($driver->id, $get('start_at'), $get('end_at') ?: $get('start_at'), null, $get('drive_mode'))) {
                $set('driver_id', $driver->id);

                return;
            }
        }
    }

    protected static function driverHint(Get $get): ?string
    {
        $car = $get('bookable_id') ? Car::find($get('bookable_id')) : null;
        $usual = $car?->drivers()->pluck('name')->implode(', ');
        $driver = $get('driver_id') ? \App\Models\User::find($get('driver_id')) : null;
        $cars = $driver?->assignedCars()->pluck('name')->implode(', ');

        return collect([
            $usual ? 'Usual driver(s) for this car: '.$usual.'.' : null,
            $driver ? $driver->name.' usually drives: '.($cars ?: 'no cars assigned yet').'.' : null,
        ])->filter()->implode(' ') ?: null;
    }

    public static function recalculate(Get $get, Set $set): void
    {
        \App\Filament\Concerns\PriceFields::recalculate($get, $set);
    }

    public static function durationText($start, $end): ?string
    {
        if (! $start || ! $end) {
            return null;
        }

        $minutes = Carbon::parse($start)->diffInMinutes(Carbon::parse($end), false);
        if ($minutes <= 0) {
            return null;
        }

        $days = intdiv((int) $minutes, 1440);
        $hours = intdiv((int) $minutes % 1440, 60);

        return trim(($days ? $days.' day'.($days > 1 ? 's' : '').' ' : '').($hours ? $hours.' hr' : ''));
    }

    /* ------------------------------------------------------------------ */
    /*  Table                                                             */
    /* ------------------------------------------------------------------ */

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::tableColumns())
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'bookable', 'driver']))
            ->defaultSort('start_at')
            ->striped()
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(BookingStatus::class)->multiple(),
                Tables\Filters\TernaryFilter::make('website')->label('Made on the website')
                    ->queries(true: fn ($query) => $query->where('source', 'website'), false: fn ($query) => $query->whereNull('source')),
                Tables\Filters\SelectFilter::make('service_type')->label('Service')->options(ServiceType::class),
                Tables\Filters\SelectFilter::make('drive_mode')->label('Rental type')->options(DriveMode::class),
                Tables\Filters\SelectFilter::make('payment_status')->label('Payment')->options(PaymentStatus::class)->multiple(),
                Tables\Filters\SelectFilter::make('driver_id')->label('Driver')->relationship('driver', 'name'),
                Tables\Filters\SelectFilter::make('customer_id')->label('Customer')->relationship('customer', 'name')->searchable(),
                Tables\Filters\Filter::make('dates')
                    ->form([
                        Forms\Components\DatePicker::make('from')->native(false),
                        Forms\Components\DatePicker::make('until')->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('end_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('start_at', '<=', $d))),
                // 5 Nov (S3): Admin's "Agents" page opens a list per agent.
                Tables\Filters\SelectFilter::make('assigned_to')->label('Agent')->relationship('assignee', 'name')
                    ->visible(fn (): bool => ! \App\Support\Access::agentScoped()),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                BookingActions::deliver(Tables\Actions\Action::class)->button()->size('sm'),
                BookingActions::receive(Tables\Actions\Action::class)->button()->size('sm'),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    BookingActions::recordPayment(Tables\Actions\Action::class),
                    BookingActions::refundDeposit(Tables\Actions\Action::class),
                    BookingActions::whatsapp(Tables\Actions\Action::class),
                    BookingActions::cancel(Tables\Actions\Action::class),
                    BookingActions::requestCancel(Tables\Actions\Action::class),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading('No bookings here')
            ->emptyStateDescription('Create a booking, or open a lead and click "Create booking".');
    }

    /** Shared with the Bookings tab on cars, yachts, trips and customers. */
    public static function tableColumns(bool $withItem = true, bool $withCustomer = true): array
    {
        return array_values(array_filter([
            Tables\Columns\TextColumn::make('reference')
                ->label('Booking')
                ->searchable()
                ->sortable()
                ->weight(FontWeight::SemiBold)
                ->description(fn (Booking $record): ?string => $withCustomer ? $record->customer?->name : $record->bookable?->name),
            $withItem ? Tables\Columns\TextColumn::make('bookable.name')
                ->label('Car / yacht / trip')
                ->placeholder('-')
                ->description(fn (Booking $record): ?string => $record->drive_mode?->getLabel()
                    .($record->driver ? ' · '.$record->driver->name : '')
                    ?: $record->service_type?->getLabel()) : null,
            Tables\Columns\TextColumn::make('start_at')
                ->label('Dates')
                ->sortable()
                ->formatStateUsing(fn (Booking $record): string => $record->start_at->format('d M, H:i').' → '.($record->end_at->isSameDay($record->start_at) ? $record->end_at->format('H:i') : $record->end_at->format('d M, H:i')))
                ->description(fn (Booking $record): string => self::durationText($record->start_at, $record->end_at)
                    .($record->status === BookingStatus::InProgress && $record->end_at->isPast() ? ' · overdue' : ''))
                ->color(fn (Booking $record): ?string => $record->status === BookingStatus::InProgress && $record->end_at->isPast() ? 'danger' : null),
            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->description(fn (Booking $record): ?string => $record->source === 'website'
                    ? ($record->hold_until ? 'Website · pay by '.$record->hold_until->format('j M H:i') : 'Website')
                    : null)
                ->sortable(),
            Tables\Columns\TextColumn::make('total')
                ->label('Total')
                ->alignEnd()
                ->sortable()
                ->formatStateUsing(fn (Booking $record): string => 'AED '.number_format($record->amountDue()))
                ->description(fn (Booking $record): ?string => (float) $record->balance > 0 ? 'Due AED '.number_format((float) $record->balance) : ($record->payment_status === PaymentStatus::Paid ? 'Paid' : null))
                ->color(fn (Booking $record): ?string => (float) $record->balance > 0 && in_array($record->status, [BookingStatus::InProgress, BookingStatus::Completed], true) ? 'danger' : null),
            Tables\Columns\TextColumn::make('payment_status')
                ->label('Payment')
                ->badge()
                ->placeholder('-')
                ->toggleable(),
            Tables\Columns\TextColumn::make('pickup_location')
                ->label('Location')
                ->limit(25)
                ->placeholder('-')
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\TransactionResource\RelationManagers\TransactionsRelationManager::class,
            BookingResource\RelationManagers\ChargeLinesRelationManager::class, // 4 Nov (Phase 4)
            BookingResource\RelationManagers\PreauthsRelationManager::class,
            \App\Filament\RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [
            BookingResource\Widgets\BookingOverview::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return Access::scopeOwn(parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]), 'booking')
            ->when(Access::isDriverOnly(), fn (Builder $q) => $q->where('driver_id', auth()->id()));
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'customer.name', 'customer.phone'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->title();
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Customer' => $record->customer?->name,
            'Dates' => $record->start_at->format('d M').' – '.$record->end_at->format('d M'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->withoutTrashed()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])->whereDate('start_at', today()))
                ->orWhere(fn (Builder $q) => $q->where('status', BookingStatus::InProgress)->where('end_at', '<=', now()->endOfDay())))
            ->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Going out or coming back today';
    }
}
