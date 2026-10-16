<?php

namespace App\Filament\Resources;

use App\Enums\TripStatus;
use App\Filament\Concerns\FleetFields;
use App\Filament\Concerns\WebsitePublishing;
use App\Filament\Resources\TripResource\Pages;
use App\Filament\Resources\TripResource\RelationManagers;
use App\Models\Category;
use App\Models\Trip;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TripResource extends Resource
{
    protected static ?string $model = Trip::class;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationGroup = 'Fleet';

    protected static ?string $navigationLabel = 'Yacht Trips';

    protected static ?string $modelLabel = 'yacht trip';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public const CATEGORIES = [
        'Sightseeing Cruise' => 'Sightseeing Cruise',
        'Sunset Cruise' => 'Sunset Cruise',
        'Dinner Cruise' => 'Dinner Cruise',
        'Party Boat' => 'Party Boat',
        'Fishing Trip' => 'Fishing Trip',
        'Water Sports' => 'Water Sports',
        'Private Experience' => 'Private Experience',
    ];

    public const DURATIONS = [
        60 => '1 hour',
        90 => '1.5 hours',
        120 => '2 hours',
        180 => '3 hours',
        240 => '4 hours',
        360 => '6 hours',
        480 => '8 hours (full day)',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Tabs::make('trip')
                    ->columnSpan(['lg' => 2])
                    ->persistTabInQueryString()
                    ->tabs([
                        self::overviewTab(),
                        self::pricingTab(),
                        self::photosTab(),
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
                                    ->options(TripStatus::class)
                                    ->default(TripStatus::Active)
                                    ->required()
                                    ->inline(),
                            ]),

                        Forms\Components\Section::make('Yacht Trips are switched off')
                            ->icon('heroicon-o-eye-slash')
                            ->iconColor('warning')
                            ->description('The whole Yacht Trips section is currently hidden on the website. Turn it on under Website › Categories.')
                            ->visible(fn (): bool => ! Category::isEnabled(Category::YACHT_TRIPS)),

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
                Forms\Components\Fieldset::make('Trip')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Trip name')
                            ->placeholder('e.g. Dubai Marina Sunset Cruise')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('category')
                            ->options(self::CATEGORIES)
                            ->native(false),
                        Forms\Components\Select::make('yacht_id')
                            ->label('Yacht')
                            ->relationship('yacht', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                // Prefill capacity and marina from the chosen yacht.
                                if ($yacht = \App\Models\Yacht::find($state)) {
                                    $set('guest_capacity', $yacht->max_guests);
                                    $set('departure_marina', $yacht->marina);
                                }
                            }),
                        Forms\Components\Select::make('duration_minutes')
                            ->label('Duration')
                            ->options(self::DURATIONS)
                            ->native(false),
                        Forms\Components\TextInput::make('location')
                            ->label('Route / area')
                            ->placeholder('Dubai Marina, Palm Jumeirah, Atlantis')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('departure_marina')
                            ->datalist(YachtResource::MARINAS)
                            ->maxLength(100),
                        Forms\Components\TextInput::make('meeting_point')
                            ->placeholder('Pier 7, gate B')
                            ->maxLength(255),
                        Forms\Components\TimePicker::make('start_time')
                            ->label('Usual start time')
                            ->seconds(false)
                            ->native(false),
                        Forms\Components\TextInput::make('min_guests')
                            ->label('Minimum guests')
                            ->numeric()
                            ->minValue(1),
                        Forms\Components\TextInput::make('guest_capacity')
                            ->label('Maximum guests')
                            ->numeric()
                            ->minValue(1),
                    ]),

                Forms\Components\RichEditor::make('description')
                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo']),

                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TagsInput::make('included_services')
                        ->label("What's included")
                        ->placeholder('Add and press Enter')
                        ->suggestions(['Soft drinks & water', 'Snacks', 'BBQ', 'Life jackets', 'Towels', 'Swimming stop', 'Live DJ', 'Photography', 'Hotel transfer']),
                    Forms\Components\TagsInput::make('excluded_services')
                        ->label('Not included')
                        ->placeholder('Add and press Enter')
                        ->suggestions(['Alcoholic drinks', 'Hotel transfer', 'Water sports', 'Gratuity', 'Photography']),
                ]),

                Forms\Components\Repeater::make('itinerary')
                    ->label('Itinerary')
                    ->helperText('Shown on the trip page as a timeline.')
                    ->columns(4)
                    ->defaultItems(0)
                    ->addActionLabel('Add a stop')
                    ->reorderable()
                    ->schema([
                        Forms\Components\TextInput::make('time')
                            ->placeholder('17:30')
                            ->maxLength(20),
                        Forms\Components\TextInput::make('title')
                            ->placeholder('Departure from Dubai Marina')
                            ->required()
                            ->columnSpan(3),
                    ]),
            ]);
    }

    protected static function pricingTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Pricing & booking')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Forms\Components\Fieldset::make('Price')
                    ->columns(3)
                    ->schema([
                        Forms\Components\ToggleButtons::make('price_unit')
                            ->label('Charged')
                            ->options(['per_person' => 'Per person', 'per_trip' => 'Per trip (private)'])
                            ->default('per_person')
                            ->inline()
                            ->live()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('price')
                            ->label(fn (Get $get): string => $get('price_unit') === 'per_trip' ? 'Price per trip' : 'Adult price')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->required(fn (Get $get): bool => (bool) $get('is_published'))
                            ->validationMessages(['required' => 'A price is needed before the trip can be shown on the website.']),
                        Forms\Components\TextInput::make('child_price')
                            ->label('Child price')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->placeholder('Same as adult')
                            ->visible(fn (Get $get): bool => $get('price_unit') !== 'per_trip'),
                    ]),

                FleetFields::addonsRepeater(
                    ['Hotel pick-up', 'Dinner upgrade', 'Drinks package', 'Photography', 'Jet ski (30 min)', 'Birthday cake'],
                    ['per_person' => 'Per person', 'per_booking' => 'Per booking'],
                ),

                Forms\Components\Textarea::make('booking_requirements')
                    ->placeholder('Passport / Emirates ID for all guests, arrive 30 minutes early...')
                    ->rows(3),
                Forms\Components\Textarea::make('cancellation_terms')
                    ->placeholder('Free cancellation up to 24 hours before departure...')
                    ->rows(3),
            ]);
    }

    protected static function photosTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Photos')
            ->icon('heroicon-o-photo')
            ->badge(fn (?Trip $record) => $record?->getMedia('gallery')->count() ?: null)
            ->schema([
                Forms\Components\SpatieMediaLibraryFileUpload::make('gallery')
                    ->hiddenLabel()
                    ->helperText('The first photo is the cover image on the website. Drag to reorder.')
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

    protected static function websiteTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Website & SEO')
            ->icon('heroicon-o-globe-alt')
            ->schema([
                WebsitePublishing::slugField('yacht-trips'),
                FleetFields::badgeSelect(['New', 'Best Seller', 'Most Popular', 'Limited Offer', 'Family Friendly']),
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
                    ->searchable(['name', 'location'])
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Trip $record): string => collect([$record->yacht?->name, $record->departure_marina])->filter()->implode(' · ')),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('duration_minutes')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): string => self::DURATIONS[$state] ?? ($state ? round($state / 60, 1).' hours' : '-'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->money('AED')
                    ->description(fn (Trip $record): string => $record->price_unit === 'per_trip' ? 'per trip' : 'per person')
                    ->sortable(),
                Tables\Columns\TextColumn::make('upcoming_dates_count')
                    ->label('Upcoming dates')
                    ->counts('upcomingDates')
                    ->badge()
                    ->color(fn (int $state): string => $state ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                WebsitePublishing::toggleColumn(),
                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->trueIcon('heroicon-s-star')
                    ->falseIcon('heroicon-o-star')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->toggleable(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('yacht'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->striped()
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(TripStatus::class),
                Tables\Filters\SelectFilter::make('category')
                    ->options(self::CATEGORIES)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('yacht')
                    ->relationship('yacht', 'name')
                    ->searchable()
                    ->preload(),
                WebsitePublishing::filter(),
                Tables\Filters\TrashedFilter::make()->label('Deleted trips'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    WebsitePublishing::tableAction(),
                    Tables\Actions\ReplicateAction::make()
                        ->label('Duplicate')
                        ->excludeAttributes(['slug', 'is_published', 'is_featured', 'deleted_at', 'upcoming_dates_count'])
                        ->beforeReplicaSaved(function (Trip $replica): void {
                            $replica->name = "{$replica->name} (copy)";
                        })
                        ->successRedirectUrl(fn (Trip $replica): string => self::getUrl('edit', ['record' => $replica])),
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
                                ->options(TripStatus::class)
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
            ->emptyStateIcon('heroicon-o-sun')
            ->emptyStateHeading('No yacht trips yet')
            ->emptyStateDescription('Create trips like sunset cruises or party boats, then add the dates they run.')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('Add trip'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DatesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrips::route('/'),
            'create' => Pages\CreateTrip::route('/create'),
            'edit' => Pages\EditTrip::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'location'];
    }
}
