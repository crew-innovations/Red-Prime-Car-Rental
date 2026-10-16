<?php

namespace App\Filament\Resources;

use App\Enums\YachtStatus;
use App\Filament\Concerns\FleetFields;
use App\Filament\Concerns\WebsitePublishing;
use App\Filament\Resources\YachtResource\Pages;
use App\Models\Yacht;
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

class YachtResource extends Resource
{
    protected static ?string $model = Yacht::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationGroup = 'Fleet';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public const TYPES = [
        'Motor Yacht' => 'Motor Yacht',
        'Superyacht' => 'Superyacht',
        'Sailing Yacht' => 'Sailing Yacht',
        'Catamaran' => 'Catamaran',
        'Speedboat' => 'Speedboat',
        'Fishing Boat' => 'Fishing Boat',
    ];

    public const MARINAS = [
        'Dubai Marina', 'Dubai Harbour', 'Bluewaters', 'Palm Jumeirah', 'Port Rashid',
        'Dubai Creek Harbour', 'Jumeirah Fishing Harbour', 'Al Seef',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Tabs::make('yacht')
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
                                    ->options(YachtStatus::class)
                                    ->default(YachtStatus::Available)
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
                Forms\Components\Fieldset::make('Yacht')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Display name')
                            ->placeholder('e.g. Azimut 68 "Ocean Pearl"')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('type')
                            ->options(self::TYPES)
                            ->native(false),
                        Forms\Components\TextInput::make('manufacturer')
                            ->label('Builder')
                            ->datalist(['Azimut', 'Sunseeker', 'Princess', 'Ferretti', 'Majesty', 'Gulf Craft', 'Riva', 'Benetti', 'Lagoon'])
                            ->maxLength(100),
                        Forms\Components\TextInput::make('model')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('year_built')
                            ->numeric()
                            ->minValue(1970)
                            ->maxValue((int) date('Y') + 1),
                        Forms\Components\TextInput::make('length_ft')
                            ->label('Length')
                            ->numeric()
                            ->minValue(10)
                            ->suffix('ft'),
                        Forms\Components\TextInput::make('marina')
                            ->label('Home marina')
                            ->datalist(self::MARINAS)
                            ->maxLength(100),
                    ]),

                Forms\Components\Fieldset::make('Capacity & crew')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('max_guests')
                            ->label('Max guests')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Forms\Components\TextInput::make('capacity')
                            ->label('Licensed capacity')
                            ->helperText('Including crew, as on the licence.')
                            ->numeric()
                            ->minValue(1),
                        Forms\Components\TextInput::make('cabins')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('bathrooms')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('crew_count')
                            ->label('Crew members')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\Toggle::make('captain_included')
                            ->label('Captain included')
                            ->default(true)
                            ->inline(false),
                    ]),

                Forms\Components\RichEditor::make('description')
                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo']),
                Forms\Components\TagsInput::make('amenities')
                    ->placeholder('Add an amenity and press Enter')
                    ->suggestions([
                        'Sundeck', 'Flybridge', 'Jacuzzi', 'BBQ Grill', 'Sound System', 'Bluetooth Speakers',
                        'Air Conditioning', 'Fishing Equipment', 'Snorkelling Gear', 'Jet Ski', 'Seabob',
                        'Water Slide', 'Towels', 'Soft Drinks & Water', 'Ice', 'Wi-Fi', 'TV',
                    ]),
                Forms\Components\KeyValue::make('specifications')
                    ->label('More specifications')
                    ->keyLabel('Specification')
                    ->valueLabel('Value')
                    ->keyPlaceholder('e.g. Engines')
                    ->valuePlaceholder('e.g. 2 x MAN 1400 hp')
                    ->reorderable(),
                Forms\Components\Textarea::make('restrictions')
                    ->label('Restrictions on board')
                    ->placeholder('No shoes on deck, no glass, no outside DJ...')
                    ->rows(3),
            ]);
    }

    protected static function pricingTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Pricing & rules')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Forms\Components\Fieldset::make('Charter rates')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('hourly_price')
                            ->label('Per hour')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->required(fn (Get $get): bool => (bool) $get('is_published'))
                            ->validationMessages(['required' => 'An hourly price is needed before the yacht can be shown on the website.']),
                        Forms\Components\TextInput::make('sunset_price')
                            ->label('Sunset cruise (2 hrs)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->placeholder('Not offered'),
                        Forms\Components\TextInput::make('half_day_price')
                            ->label('Half day (4 hrs)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->placeholder('On request'),
                        Forms\Components\TextInput::make('full_day_price')
                            ->label('Full day (8 hrs)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED')
                            ->placeholder('On request'),
                        Forms\Components\TextInput::make('deposit')
                            ->label('Security deposit')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AED'),
                    ]),

                Forms\Components\Fieldset::make('Charter rules')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('minimum_hours')
                            ->label('Minimum booking')
                            ->numeric()
                            ->minValue(1)
                            ->default(2)
                            ->suffix('hours'),
                        Forms\Components\Toggle::make('fuel_included')
                            ->label('Fuel included in price')
                            ->default(true)
                            ->inline(false),
                    ]),

                FleetFields::addonsRepeater(
                    ['Catering / BBQ package', 'Birthday cake & decoration', 'DJ', 'Photographer', 'Jet ski (per hour)', 'Seabob', 'Fishing equipment', 'Champagne'],
                    ['per_booking' => 'Per booking', 'per_hour' => 'Per hour', 'per_guest' => 'Per guest'],
                ),

                Forms\Components\Textarea::make('terms')
                    ->label('Charter terms')
                    ->placeholder('Cancellation, weather policy, guest list deadline, ID requirements...')
                    ->rows(4),
            ]);
    }

    protected static function photosTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Photos')
            ->icon('heroicon-o-photo')
            ->badge(fn (?Yacht $record) => $record?->getMedia('gallery')->count() ?: null)
            ->schema([
                Forms\Components\SpatieMediaLibraryFileUpload::make('gallery')
                    ->hiddenLabel()
                    ->helperText('The first photo is the cover image on the website. Drag to reorder. Up to 30 photos, 5 MB each.')
                    ->collection('gallery')
                    ->disk('uploads')
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->imageEditor()
                    ->maxFiles(30)
                    ->maxSize(5120)
                    ->panelLayout('grid'),
            ]);
    }

    protected static function documentsTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Documents')
            ->icon('heroicon-o-document-text')
            ->badge(fn (?Yacht $record) => count($record?->documentAlerts() ?? []) ?: null)
            ->badgeColor('danger')
            ->schema([
                Forms\Components\Fieldset::make('Marine licence')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('license_number')
                            ->label('Licence / registration no.')
                            ->maxLength(100),
                        FleetFields::expiryDate('license_expiry', 'Licence expiry'),
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
                    ]),
                FleetFields::ownershipFieldset('Our yacht', 'Partner yacht'),
            ]);
    }

    protected static function websiteTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Website & SEO')
            ->icon('heroicon-o-globe-alt')
            ->schema([
                WebsitePublishing::slugField('yachts'),
                FleetFields::badgeSelect(['New', 'Best Seller', 'Most Popular', 'Limited Offer', 'Party Ready']),
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
                    ->searchable(['name', 'manufacturer', 'model', 'license_number'])
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Yacht $record): string => collect([
                        $record->manufacturer,
                        $record->length_ft ? "{$record->length_ft} ft" : null,
                        $record->marina,
                    ])->filter()->implode(' · ')),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('max_guests')
                    ->label('Guests')
                    ->icon('heroicon-m-users')
                    ->sortable(),
                Tables\Columns\TextColumn::make('hourly_price')
                    ->label('Per hour')
                    ->money('AED')
                    ->sortable(),
                Tables\Columns\TextColumn::make('full_day_price')
                    ->label('Full day')
                    ->money('AED')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\SelectColumn::make('status')
                    ->options(YachtStatus::class)
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
                FleetFields::ownershipColumn(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->striped()
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(YachtStatus::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('type')
                    ->options(self::TYPES)
                    ->multiple(),
                WebsitePublishing::filter(),
                Tables\Filters\TernaryFilter::make('is_featured')->label('Featured'),
                Tables\Filters\SelectFilter::make('ownership')
                    ->options(['own' => 'Our yachts', 'partner' => 'Partner yachts']),
                FleetFields::documentsDueFilter(),
                Tables\Filters\TrashedFilter::make()->label('Archived yachts'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    WebsitePublishing::tableAction(),
                    Tables\Actions\ReplicateAction::make()
                        ->label('Duplicate')
                        ->excludeAttributes(['slug', 'is_published', 'is_featured', 'license_number', 'deleted_at'])
                        ->beforeReplicaSaved(function (Yacht $replica): void {
                            $replica->name = "{$replica->name} (copy)";
                        })
                        ->successRedirectUrl(fn (Yacht $replica): string => self::getUrl('edit', ['record' => $replica])),
                    Tables\Actions\DeleteAction::make()->label('Archive')->icon('heroicon-o-archive-box'),
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
                                ->options(YachtStatus::class)
                                ->required()
                                ->native(false),
                        ])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['status' => $data['status']]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()->label('Archive selected'),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-lifebuoy')
            ->emptyStateHeading('No yachts yet')
            ->emptyStateDescription('Add your first yacht. Once published, it appears on the website automatically.')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('Add yacht'),
            ]);
    }

    public static function getWidgets(): array
    {
        return [
            YachtResource\Widgets\YachtOverview::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListYachts::route('/'),
            'create' => Pages\CreateYacht::route('/create'),
            'edit' => Pages\EditYacht::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'manufacturer', 'model', 'license_number'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Status' => $record->status?->getLabel(),
            'Per hour' => $record->hourly_price ? 'AED '.number_format((float) $record->hourly_price) : '-',
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('status', YachtStatus::Available)->count();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Available now';
    }
}
