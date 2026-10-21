<?php

namespace App\Filament\Resources;

use App\Enums\TripStatus;
use App\Filament\Concerns\FleetFields;
use App\Filament\Concerns\GuardedResource;
use App\Filament\Concerns\WebsitePublishing;
use App\Filament\Resources\PackageResource\Pages;
use App\Filament\Resources\PackageResource\RelationManagers;
use App\Models\Category;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Support\Options;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * Trips & Packages: one-day trips, multi-day tours, safaris and full packages.
 * Everything the website shows about a package is edited here.
 */
class PackageResource extends Resource
{
    use GuardedResource;

    protected static function manageAbility(): string
    {
        return 'manage-fleet';
    }

    protected static function deleteAbility(): string
    {
        return 'manage-fleet';
    }

    protected static ?string $model = Package::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Trips & Packages';

    protected static ?string $modelLabel = 'package';

    protected static ?string $pluralModelLabel = 'Trips & Packages';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public const MEALS = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];

    public static function form(Form $form): Form
    {
        return $form
            ->disabled(fn (): bool => ! static::canModify())
            ->columns(3)
            ->schema([
                Forms\Components\Tabs::make('package')
                    ->columnSpan(['lg' => 2])
                    ->persistTabInQueryString()
                    ->tabs([
                        self::basicsTab(),
                        self::itineraryTab(),
                        self::pricingTab(),
                        self::includedTab(),
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
                                    ->default(TripStatus::Active->value)
                                    ->required()
                                    ->inline(),
                            ]),
                        Forms\Components\Section::make('Trips & Packages are switched off')
                            ->icon('heroicon-o-eye-slash')
                            ->iconColor('warning')
                            ->description('The whole Trips & Packages section is hidden on the website. Turn it on from the Trips & Packages list.')
                            ->visible(fn (): bool => ! Category::isEnabled(Package::CATEGORY)),
                        WebsitePublishing::formSection(),
                        FleetFields::notesSection(),
                        FleetFields::metaSection(),
                    ]),
            ]);
    }

    protected static function basicsTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Basics')
            ->icon('heroicon-o-information-circle')
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Package name')
                    ->placeholder('e.g. 3-Day Liwa Desert Safari')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\Select::make('package_category_id')
                        ->label('Category')
                        ->relationship('category', 'name')
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->required()->maxLength(120),
                        ])
                        ->helperText('Groups packages on the website.'),
                    Forms\Components\Select::make('product_type')
                        ->label('Type')
                        ->options(fn (?Package $record): array => collect(Options::list('package_types'))
                            ->when($record?->product_type, fn ($c, $current) => $c->push($current))
                            ->unique()->mapWithKeys(fn ($t) => [$t => $t])->all())
                        ->native(false)
                        ->helperText('Edit the list in Business settings › Lists.'),
                    Forms\Components\TextInput::make('duration_days')
                        ->label('Days')
                        ->numeric()->minValue(1)->maxValue(60)
                        ->live(onBlur: true),
                    Forms\Components\TextInput::make('duration_nights')
                        ->label('Nights')
                        ->numeric()->minValue(0)->maxValue(60),
                    Forms\Components\TextInput::make('start_location')
                        ->label('Starts from')
                        ->placeholder('Dubai (hotel pick-up)')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('meeting_point')
                        ->placeholder('Hotel lobby, 07:00')
                        ->maxLength(255),
                ]),
                Forms\Components\TagsInput::make('destinations')
                    ->placeholder('Add a place and press Enter')
                    ->suggestions(['Dubai', 'Abu Dhabi', 'Liwa', 'Hatta', 'Ras Al Khaimah', 'Fujairah', 'Al Ain', 'Musandam']),
                Forms\Components\Textarea::make('summary')
                    ->label('Short description')
                    ->helperText('One or two sentences for cards and search results.')
                    ->rows(2)
                    ->maxLength(500),
                Forms\Components\RichEditor::make('description')
                    ->label('Full description')
                    ->toolbarButtons(['bold', 'italic', 'h3', 'bulletList', 'orderedList', 'link', 'undo', 'redo']),
            ]);
    }

    protected static function itineraryTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Itinerary')
            ->icon('heroicon-o-queue-list')
            ->badge(fn (Get $get): ?int => count((array) $get('itinerary')) ?: null)
            ->schema([
                Forms\Components\Repeater::make('itinerary')
                    ->hiddenLabel()
                    ->helperText('One block per day, in order. Drag to reorder. The website shows exactly these days.')
                    ->addActionLabel('Add day')
                    ->defaultItems(0)
                    ->reorderable()
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->cloneable()
                    ->itemLabel(function (array $state, string $uuid, Forms\Components\Repeater $component): string {
                        $day = array_search($uuid, array_keys((array) $component->getState()), true);

                        return 'Day '.(($day === false ? 0 : $day) + 1).(filled($state['title'] ?? null) ? ' · '.$state['title'] : '');
                    })
                    ->hintAction(
                        Forms\Components\Actions\Action::make('fillDays')
                            ->label('Match the number of days')
                            ->icon('heroicon-m-plus-circle')
                            ->visible(fn (Get $get): bool => (int) $get('duration_days') > count((array) $get('itinerary')))
                            ->action(function (Get $get, Set $set): void {
                                $days = (array) $get('itinerary');
                                for ($i = count($days); $i < (int) $get('duration_days'); $i++) {
                                    $days[(string) Str::uuid()] = ['title' => null, 'description' => null];
                                }
                                $set('itinerary', $days);
                            })
                    )
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Day title')
                            ->placeholder('Arrival & desert camp')
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('description')
                            ->label('What happens')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\TagsInput::make('destinations')->label('Places')->placeholder('Add and press Enter'),
                        Forms\Components\TagsInput::make('activities')->placeholder('Add and press Enter'),
                        Forms\Components\TagsInput::make('included')->label('Included that day')->placeholder('Add and press Enter'),
                        Forms\Components\CheckboxList::make('meals')->options(self::MEALS)->columns(3),
                        Forms\Components\TextInput::make('accommodation')->placeholder('Desert camp, deluxe tent')->maxLength(255),
                        Forms\Components\FileUpload::make('image')
                            ->label('Photo (optional)')
                            ->image()
                            ->disk('uploads')
                            ->directory('packages/itinerary')
                            ->visibility('public')
                            ->maxSize(5120),
                    ]),
            ]);
    }

    protected static function pricingTab(): Forms\Components\Tabs\Tab
    {
        $perPerson = fn (Get $get): bool => $get('pricing_mode') !== 'fixed';

        return Forms\Components\Tabs\Tab::make('Pricing & dates')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Forms\Components\Fieldset::make('Price')
                    ->columns(3)
                    ->schema([
                        Forms\Components\ToggleButtons::make('pricing_mode')
                            ->label('Charged')
                            ->options(Package::PRICING_MODES)
                            ->default('per_person')
                            ->inline()
                            ->live()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('price')
                            ->label(fn (Get $get): string => $perPerson($get) ? 'Price per person' : 'Package price')
                            ->numeric()->minValue(0)
                            ->prefix(fn (Get $get): string => $get('currency') ?: 'AED')
                            ->required(fn (Get $get): bool => (bool) $get('is_published'))
                            ->validationMessages(['required' => 'A price is needed before the package can be shown on the website.']),
                        Forms\Components\TextInput::make('sale_price')
                            ->label('Offer price')
                            ->helperText('Optional. Used when lower than the price.')
                            ->numeric()->minValue(0)
                            ->prefix(fn (Get $get): string => $get('currency') ?: 'AED'),
                        Forms\Components\TextInput::make('child_price')
                            ->label('Child price')
                            ->numeric()->minValue(0)
                            ->prefix(fn (Get $get): string => $get('currency') ?: 'AED')
                            ->visible($perPerson),
                        Forms\Components\TextInput::make('currency')
                            ->default('AED')
                            ->required()
                            ->length(3)
                            ->live(onBlur: true)
                            ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state ?: 'AED')),
                    ]),
                Forms\Components\Repeater::make('price_tiers')
                    ->label('Group prices')
                    ->helperText('Optional. A lower per-person price from a group size, e.g. 6+ guests at AED 750.')
                    ->visible($perPerson)
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add group price')
                    ->schema([
                        Forms\Components\TextInput::make('min_guests')->label('From guests')->numeric()->minValue(2)->required(),
                        Forms\Components\TextInput::make('price')->label('Price per person')->numeric()->minValue(0)->required()
                            ->prefix(fn (Get $get): string => $get('../../currency') ?: 'AED'),
                    ]),
                Forms\Components\Fieldset::make('Booking')
                    ->columns(3)
                    ->schema([
                        Forms\Components\ToggleButtons::make('booking_mode')
                            ->label('Dates')
                            ->options(Package::BOOKING_MODES)
                            ->default('on_request')
                            ->inline()
                            ->live()
                            ->helperText(fn (Get $get): string => $get('booking_mode') === 'departures'
                                ? 'Add the dates under "Departures" below this form.'
                                : 'The customer asks for a date; staff confirm it.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('min_guests')->label('Minimum guests')->numeric()->minValue(1),
                        Forms\Components\TextInput::make('max_guests')->label('Maximum guests per booking')->numeric()->minValue(1),
                        Forms\Components\TextInput::make('default_capacity')
                            ->label('Places per departure')
                            ->helperText('Used when a departure has no own number.')
                            ->numeric()->minValue(1)
                            ->visible(fn (Get $get): bool => $get('booking_mode') === 'departures'),
                        Forms\Components\TextInput::make('booking_cutoff_days')
                            ->label('Book at least')
                            ->suffix('days before')
                            ->numeric()->minValue(0),
                    ]),
            ]);
    }

    protected static function includedTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make("What's included")
            ->icon('heroicon-o-check-circle')
            ->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TagsInput::make('inclusions')
                        ->label('Included')
                        ->placeholder('Add and press Enter')
                        ->suggestions(['Hotel pick-up & drop-off', 'All meals', 'Bottled water', 'Professional guide', 'Entrance tickets', '4x4 transport', 'Accommodation']),
                    Forms\Components\TagsInput::make('exclusions')
                        ->label('Not included')
                        ->placeholder('Add and press Enter')
                        ->suggestions(['Flights', 'Travel insurance', 'Tips', 'Personal expenses', 'Alcoholic drinks']),
                ]),
                Forms\Components\TagsInput::make('activities')
                    ->placeholder('Add and press Enter')
                    ->suggestions(['Dune bashing', 'Camel ride', 'Sandboarding', 'Stargazing', 'BBQ dinner', 'City tour', 'Snorkelling']),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Textarea::make('accommodation')->rows(3),
                    Forms\Components\Textarea::make('meals')->rows(3),
                    Forms\Components\Textarea::make('transport')->label('Transport')->rows(3),
                ]),
                Forms\Components\Textarea::make('requirements')
                    ->label('What customers need to know / bring')
                    ->placeholder('Passport copy, comfortable shoes, sunscreen…')
                    ->rows(3),
                Forms\Components\Textarea::make('terms')
                    ->label('Terms shown on the website')
                    ->rows(4),
            ]);
    }

    protected static function photosTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Photos')
            ->icon('heroicon-o-photo')
            ->badge(fn (?Package $record) => $record ? ($record->getMedia('gallery')->count() + $record->getMedia('cover')->count()) ?: null : null)
            ->schema([
                Forms\Components\SpatieMediaLibraryFileUpload::make('cover')
                    ->label('Cover photo')
                    ->collection('cover')
                    ->disk('uploads')
                    ->image()
                    ->imageEditor()
                    ->maxSize(5120),
                Forms\Components\SpatieMediaLibraryFileUpload::make('gallery')
                    ->label('Gallery')
                    ->helperText('Drag to reorder.')
                    ->collection('gallery')
                    ->disk('uploads')
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->maxFiles(30)
                    ->maxSize(5120)
                    ->panelLayout('grid'),
            ]);
    }

    protected static function websiteTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Website & SEO')
            ->icon('heroicon-o-globe-alt')
            ->schema([
                WebsitePublishing::slugField('packages'),
                FleetFields::badgeSelect(['New', 'Best Seller', 'Most Popular', 'Limited Offer', 'Family Friendly']),
                ...WebsitePublishing::seoFields(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\SpatieMediaLibraryImageColumn::make('cover')
                    ->label('')
                    ->collection('cover')
                    ->conversion('thumb')
                    ->height(48)
                    ->width(72)
                    ->extraImgAttributes(['class' => 'rounded-lg object-cover']),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(['name', 'start_location'])
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Package $record): ?string => collect([$record->category?->name, $record->product_type])->filter()->implode(' · ') ?: null),
                Tables\Columns\TextColumn::make('duration_days')
                    ->label('Duration')
                    ->state(fn (Package $record): ?string => $record->durationLabel())
                    ->placeholder('-')
                    ->sortable(['duration_days']),
                Tables\Columns\TextColumn::make('price')
                    ->state(fn (Package $record): ?string => ($p = $record->unitPrice()) !== null ? $record->currency.' '.number_format($p) : null)
                    ->description(fn (Package $record): string => $record->isPerPerson() ? 'per person' : 'per package')
                    ->placeholder('No price')
                    ->sortable(['price']),
                Tables\Columns\TextColumn::make('next_departure')
                    ->label('Dates')
                    ->state(function (Package $record): string {
                        if (! $record->usesDepartures()) {
                            return 'On request';
                        }
                        $next = $record->upcomingDepartures->first();

                        return $next ? $next->start_date->format('d M Y').($record->upcomingDepartures->count() > 1 ? ' +'.($record->upcomingDepartures->count() - 1) : '') : 'No upcoming dates';
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'No upcoming dates' ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'upcomingDepartures', 'media']))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(TripStatus::class),
                Tables\Filters\SelectFilter::make('package_category_id')->label('Category')->relationship('category', 'name'),
                Tables\Filters\SelectFilter::make('product_type')->label('Type')
                    ->options(fn (): array => Package::query()->whereNotNull('product_type')->distinct()->pluck('product_type', 'product_type')->all()),
                WebsitePublishing::filter(),
                Tables\Filters\TrashedFilter::make()->label('Deleted packages'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('preview')
                        ->label('Preview')
                        ->icon('heroicon-o-eye')
                        ->url(fn (Package $record): string => route('crm.preview', ['type' => 'package', 'id' => $record->id]))
                        ->openUrlInNewTab(),
                    WebsitePublishing::tableAction(),
                    Tables\Actions\ReplicateAction::make()
                        ->label('Duplicate')
                        ->excludeAttributes(['slug', 'is_published', 'is_featured', 'deleted_at'])
                        ->beforeReplicaSaved(function (Package $replica): void {
                            $replica->name = "{$replica->name} (copy)";
                        })
                        ->successRedirectUrl(fn (Package $replica): string => self::getUrl('edit', ['record' => $replica])),
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
                        ->form([Forms\Components\Select::make('status')->options(TripStatus::class)->required()->native(false)])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['status' => $data['status']]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-map')
            ->emptyStateHeading('No trips or packages yet')
            ->emptyStateDescription('Create one-day trips, multi-day tours or full packages, with a day-by-day itinerary.')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('Add package'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DeparturesRelationManager::class,
            \App\Filament\Resources\BookingResource\RelationManagers\BookingsRelationManager::class,
            \App\Filament\RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'start_location'];
    }
}
