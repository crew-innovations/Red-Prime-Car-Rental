<?php

namespace App\Filament\Resources;

use App\Enums\CarStatus;
use App\Filament\Concerns\FleetFields;
use App\Filament\Concerns\WebsitePublishing;
use App\Filament\Resources\CarResource\Pages;
use App\Filament\Resources\CarResource\RelationManagers;
use App\Models\Car;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CarResource extends Resource
{
    protected static ?string $model = Car::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Fleet';

    protected static ?string $navigationLabel = 'Cars';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public const TYPES = [
        'Sedan' => 'Sedan',
        'SUV' => 'SUV',
        'Sports' => 'Sports',
        'Supercar' => 'Supercar',
        'Luxury' => 'Luxury',
        'Convertible' => 'Convertible',
        'Coupe' => 'Coupe',
        'Hatchback' => 'Hatchback',
        'Van' => 'Van / MPV',
    ];

    public const BRANDS = [
        'Audi', 'Bentley', 'BMW', 'Cadillac', 'Chevrolet', 'Ferrari', 'Ford', 'GMC', 'Honda',
        'Hyundai', 'Kia', 'Lamborghini', 'Land Rover', 'Lexus', 'Maserati', 'McLaren',
        'Mercedes-Benz', 'Mitsubishi', 'Nissan', 'Porsche', 'Rolls-Royce', 'Tesla', 'Toyota',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Tabs::make('car')
                    ->columnSpan(['lg' => 2])
                    ->persistTabInQueryString()
                    ->tabs([
                        self::overviewTab(),
                        self::pricingTab(),
                        self::photosTab(),
                        self::documentsTab(),
                        self::websiteTab(),
                    ]),

                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Forms\Components\Section::make('Status')
                            ->icon('heroicon-o-signal')
                            ->schema([
                                Forms\Components\ToggleButtons::make('status')
                                    ->hiddenLabel()
                                    ->options(CarStatus::class)
                                    ->default(CarStatus::Available)
                                    ->required()
                                    ->columns(2),
                            ]),

                        FleetFields::alertsSection(),

                        WebsitePublishing::formSection(),

                        FleetFields::notesSection(),

                        FleetFields::metaSection(),
                    ]),
            ]);
    }

    protected static function overviewTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Overview')
            ->icon('heroicon-o-information-circle')
            ->schema([
                Forms\Components\Fieldset::make('Vehicle')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Display name')
                            ->placeholder('e.g. Lamborghini Urus 2024')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('brand')
                            ->required()
                            ->datalist(self::BRANDS)
                            ->maxLength(100),
                        Forms\Components\TextInput::make('model')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\TextInput::make('year')
                            ->numeric()
                            ->minValue(1990)
                            ->maxValue((int) date('Y') + 1),
                        Forms\Components\Select::make('type')
                            ->options(self::TYPES)
                            ->native(false),
                        Forms\Components\TextInput::make('colour')
                            ->label('Exterior colour')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('interior_colour')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('mileage')
                            ->label('Current mileage')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('km'),
                        Forms\Components\TextInput::make('branch')
                            ->label('Branch / location')
                            ->datalist(['Dubai Marina', 'Downtown Dubai', 'Business Bay', 'JBR', 'Palm Jumeirah', 'Deira', 'Abu Dhabi'])
                            ->maxLength(100),
                    ]),

                Forms\Components\Fieldset::make('Specs shown on the website')
                    ->columns(4)
                    ->schema([
                        Forms\Components\Select::make('transmission')
                            ->options(['Automatic' => 'Automatic', 'Manual' => 'Manual'])
                            ->default('Automatic')
                            ->native(false),
                        Forms\Components\Select::make('fuel')
                            ->options(['Petrol' => 'Petrol', 'Diesel' => 'Diesel', 'Hybrid' => 'Hybrid', 'Electric' => 'Electric'])
                            ->default('Petrol')
                            ->native(false),
                        Forms\Components\TextInput::make('engine')
                            ->placeholder('4.0L V8 Twin Turbo')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('horsepower')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('hp'),
                        Forms\Components\TextInput::make('seats')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(20),
                        Forms\Components\TextInput::make('doors')
                            ->numeric()
                            ->minValue(2)
                            ->maxValue(6),
                        Forms\Components\TextInput::make('luggage')
                            ->label('Bags')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(10),
                    ]),

                Forms\Components\RichEditor::make('description')
                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo']),
                Forms\Components\TagsInput::make('features')
                    ->placeholder('Add a feature and press Enter')
                    ->suggestions([
                        'GPS Navigation', 'Bluetooth', 'Apple CarPlay', 'Android Auto', 'Sunroof',
                        'Panoramic Roof', 'Leather Seats', 'Heated Seats', 'Ventilated Seats', '360° Camera',
                        'Parking Sensors', 'Cruise Control', 'Keyless Entry', 'Premium Sound System', 'Ambient Lighting',
                    ]),
                Forms\Components\KeyValue::make('specifications')
                    ->label('More specifications')
                    ->keyLabel('Specification')
                    ->valueLabel('Value')
                    ->keyPlaceholder('e.g. 0-100 km/h')
                    ->valuePlaceholder('e.g. 3.6 s')
                    ->reorderable(),
            ]);
    }

    protected static function pricingTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Pricing & rules')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Forms\Components\Fieldset::make('Rates')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('daily_price')
                            ->label('Per day')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->required(fn (Get $get): bool => (bool) $get('is_published'))
                            ->validationMessages(['required' => 'A daily price is needed before the car can be shown on the website.']),
                        Forms\Components\TextInput::make('weekly_price')
                            ->label('Per week')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->placeholder('On request'),
                        Forms\Components\TextInput::make('monthly_price')
                            ->label('Per month')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->placeholder('On request'),
                        Forms\Components\TextInput::make('hourly_price')
                            ->label('Per hour')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->placeholder('Not offered'),
                        Forms\Components\TextInput::make('chauffeur_daily_price')
                            ->label('With driver, per day')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->placeholder('Not offered'),
                        Forms\Components\TextInput::make('deposit')
                            ->label('Security deposit')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED'),
                    ]),

                Forms\Components\Fieldset::make('Rental rules')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('min_rental_days')
                            ->label('Minimum rental')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->suffix('days'),
                        Forms\Components\TextInput::make('min_driver_age')
                            ->label('Minimum driver age')
                            ->numeric()
                            ->minValue(18)
                            ->maxValue(80)
                            ->default(21)
                            ->suffix('years'),
                        Forms\Components\Select::make('insurance_type')
                            ->label('Insurance included')
                            ->options([
                                'basic' => 'Basic (third party)',
                                'comprehensive' => 'Comprehensive',
                                'full' => 'Full cover, zero excess',
                            ])
                            ->default('basic')
                            ->native(false),
                        Forms\Components\TextInput::make('daily_km_limit')
                            ->label('Km limit per day')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('km')
                            ->placeholder('Unlimited'),
                        Forms\Components\TextInput::make('extra_km_charge')
                            ->label('Extra km charge')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->suffix('/ km'),
                        Forms\Components\TextInput::make('salik_charge')
                            ->label('Salik (toll) charge')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->suffix('/ crossing'),
                        Forms\Components\Toggle::make('delivery_available')
                            ->label('Delivery & pick-up available')
                            ->default(true)
                            ->live()
                            ->inline(false),
                        Forms\Components\TextInput::make('delivery_fee')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->placeholder('Free')
                            ->visible(fn (Get $get): bool => (bool) $get('delivery_available')),
                    ]),

                FleetFields::addonsRepeater(
                    ['Child seat', 'Additional driver', 'Full insurance upgrade', 'GPS device', 'Airport delivery', 'Wi-Fi hotspot'],
                    ['per_day' => 'Per day', 'per_rental' => 'Per rental'],
                ),

                Forms\Components\Textarea::make('terms')
                    ->label('Other rental terms')
                    ->placeholder('Fuel policy, cross-border rules, cancellation...')
                    ->rows(4),
            ]);
    }

    protected static function photosTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Photos')
            ->icon('heroicon-o-photo')
            ->badge(fn (?Car $record) => $record?->getMedia('gallery')->count() ?: null)
            ->schema([
                Forms\Components\SpatieMediaLibraryFileUpload::make('gallery')
                    ->hiddenLabel()
                    ->helperText('The first photo is the cover image on the website. Drag to reorder. Up to 20 photos, 5 MB each.')
                    ->collection('gallery')
                    ->disk('uploads')
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->imageEditor()
                    ->maxFiles(20)
                    ->maxSize(5120)
                    ->panelLayout('grid'),
            ]);
    }

    protected static function documentsTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Documents')
            ->icon('heroicon-o-document-text')
            ->badge(fn (?Car $record) => count($record?->documentAlerts() ?? []) ?: null)
            ->badgeColor('danger')
            ->schema([
                Forms\Components\Fieldset::make('Registration (Mulkiya)')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('registration_number')
                            ->label('Plate number')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('vin')
                            ->label('VIN / chassis no.')
                            ->maxLength(50),
                        FleetFields::expiryDate('registration_expiry', 'Registration expiry'),
                    ]),

                Forms\Components\Fieldset::make('Insurance')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('insurance_company')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('insurance_policy_no')
                            ->label('Policy number')
                            ->maxLength(100),
                        FleetFields::expiryDate('insurance_expiry', 'Insurance expiry'),
                    ]),

                Forms\Components\Fieldset::make('Service')
                    ->columns(3)
                    ->schema([
                        FleetFields::expiryDate('next_service_date', 'Next service date'),
                        Forms\Components\TextInput::make('next_service_km')
                            ->label('Next service at')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('km'),
                    ]),

                FleetFields::ownershipFieldset('Our fleet', 'Partner car'),

                Forms\Components\Placeholder::make('documents_note')
                    ->hiddenLabel()
                    ->content('Document scans (Mulkiya, insurance) are uploaded in the Documents module in Milestone 2. Never shown on the website.'),
            ]);
    }

    protected static function websiteTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Website & SEO')
            ->icon('heroicon-o-globe-alt')
            ->schema([
                WebsitePublishing::slugField('cars'),
                FleetFields::badgeSelect(['New', 'Best Seller', 'Most Popular', 'Limited Offer', 'Chauffeur Available']),
                ...WebsitePublishing::seoFields(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\SpatieMediaLibraryImageColumn::make('gallery')
                    ->label('')
                    ->collection('gallery')
                    ->conversion('thumb')
                    ->limit(1)
                    ->height(48)
                    ->width(72)
                    ->extraImgAttributes(['class' => 'rounded-lg object-cover']),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(['name', 'brand', 'model', 'registration_number'])
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Car $record): string => collect([$record->brand, $record->model, $record->year])->filter()->implode(' · ')),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('daily_price')
                    ->label('Per day')
                    ->money('AED')
                    ->sortable(),
                Tables\Columns\TextColumn::make('weekly_price')
                    ->label('Per week')
                    ->money('AED')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('monthly_price')
                    ->label('Per month')
                    ->money('AED')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\SelectColumn::make('status')
                    ->options(CarStatus::class)
                    ->selectablePlaceholder(false)
                    ->sortable(),
                FleetFields::documentsColumn(),
                WebsitePublishing::toggleColumn(),
                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->trueIcon('heroicon-s-star')
                    ->falseIcon('heroicon-o-star')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('website_badge')
                    ->label('Badge')
                    ->badge()
                    ->color('warning')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('registration_number')
                    ->label('Plate')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('branch')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                FleetFields::ownershipColumn(),
                Tables\Columns\TextColumn::make('mileage')
                    ->numeric()
                    ->suffix(' km')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filtersFormColumns(2)
            ->striped()
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(CarStatus::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('type')
                    ->options(self::TYPES)
                    ->multiple(),
                WebsitePublishing::filter(),
                Tables\Filters\TernaryFilter::make('is_featured')->label('Featured'),
                Tables\Filters\SelectFilter::make('ownership')
                    ->options(['own' => 'Our fleet', 'partner' => 'Partner cars']),
                FleetFields::documentsDueFilter(),
                Tables\Filters\TrashedFilter::make()->label('Deleted cars'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    WebsitePublishing::tableAction(),
                    Tables\Actions\ReplicateAction::make()
                        ->label('Duplicate')
                        ->excludeAttributes(['slug', 'is_published', 'is_featured', 'registration_number', 'deleted_at'])
                        ->beforeReplicaSaved(function (Car $replica): void {
                            $replica->name = "{$replica->name} (copy)";
                        })
                        ->successRedirectUrl(fn (Car $replica): string => self::getUrl('edit', ['record' => $replica])),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                    Tables\Actions\ForceDeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    ...WebsitePublishing::bulkActions(),
                    Tables\Actions\BulkAction::make('changeStatus')
                        ->label('Change status')
                        ->icon('heroicon-o-signal')
                        ->form([
                            Forms\Components\Select::make('status')
                                ->options(CarStatus::class)
                                ->required()
                                ->native(false),
                        ])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['status' => $data['status']]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-truck')
            ->emptyStateHeading('No cars yet')
            ->emptyStateDescription('Add your first car. Once published, it appears on the website automatically.')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('Add car'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MaintenancesRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [
            CarResource\Widgets\FleetOverview::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCars::route('/'),
            'create' => Pages\CreateCar::route('/create'),
            'edit' => Pages\EditCar::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'brand', 'model', 'registration_number'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Status' => $record->status?->getLabel(),
            'Per day' => $record->daily_price ? 'AED '.number_format((float) $record->daily_price) : '-',
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('status', CarStatus::Available)->count();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Available now';
    }
}
