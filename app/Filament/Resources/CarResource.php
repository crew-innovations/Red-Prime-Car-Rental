<?php

namespace App\Filament\Resources;

use App\Enums\CarStatus;
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
                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Forms\Components\Section::make('Vehicle details')
                            ->columns(2)
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
                                Forms\Components\Select::make('transmission')
                                    ->options(['Automatic' => 'Automatic', 'Manual' => 'Manual'])
                                    ->default('Automatic')
                                    ->native(false),
                                Forms\Components\Select::make('fuel')
                                    ->options(['Petrol' => 'Petrol', 'Diesel' => 'Diesel', 'Hybrid' => 'Hybrid', 'Electric' => 'Electric'])
                                    ->default('Petrol')
                                    ->native(false),
                                Forms\Components\TextInput::make('seats')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(20),
                                Forms\Components\TextInput::make('colour')
                                    ->maxLength(50),
                                Forms\Components\TextInput::make('registration_number')
                                    ->label('Plate / registration no.')
                                    ->helperText('Internal only, never shown on the website.')
                                    ->maxLength(50),
                                Forms\Components\TextInput::make('mileage')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('km'),
                            ]),

                        Forms\Components\Section::make('Pricing')
                            ->description('Leave weekly or monthly empty to show "price on request".')
                            ->columns(4)
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
                                    ->prefix('AED'),
                                Forms\Components\TextInput::make('monthly_price')
                                    ->label('Per month')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('AED'),
                                Forms\Components\TextInput::make('deposit')
                                    ->label('Security deposit')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('AED'),
                            ]),

                        Forms\Components\Section::make('Photos')
                            ->description('The first photo is used as the cover image. Drag to reorder.')
                            ->schema([
                                Forms\Components\SpatieMediaLibraryFileUpload::make('gallery')
                                    ->hiddenLabel()
                                    ->collection('gallery')
                                    ->disk('uploads')
                                    ->multiple()
                                    ->reorderable()
                                    ->image()
                                    ->imageEditor()
                                    ->maxFiles(20)
                                    ->maxSize(5120)
                                    ->panelLayout('grid'),
                            ]),

                        Forms\Components\Section::make('Description & features')
                            ->collapsible()
                            ->schema([
                                Forms\Components\RichEditor::make('description')
                                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo']),
                                Forms\Components\TagsInput::make('features')
                                    ->placeholder('Add a feature and press Enter')
                                    ->suggestions([
                                        'GPS Navigation', 'Bluetooth', 'Apple CarPlay', 'Android Auto', 'Sunroof',
                                        'Panoramic Roof', 'Leather Seats', 'Heated Seats', '360° Camera',
                                        'Parking Sensors', 'Cruise Control', 'Keyless Entry', 'Premium Sound System',
                                    ]),
                                Forms\Components\KeyValue::make('specifications')
                                    ->keyLabel('Specification')
                                    ->valueLabel('Value')
                                    ->keyPlaceholder('e.g. Engine')
                                    ->valuePlaceholder('e.g. 4.0L V8 Twin Turbo')
                                    ->reorderable(),
                                Forms\Components\Textarea::make('terms')
                                    ->label('Rental terms')
                                    ->placeholder('Minimum age, mileage limit, Salik / fuel policy...')
                                    ->rows(4),
                            ]),
                    ]),

                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Forms\Components\Section::make('Status')
                            ->icon('heroicon-o-signal')
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->hiddenLabel()
                                    ->options(CarStatus::class)
                                    ->default(CarStatus::Available)
                                    ->required()
                                    ->native(false),
                            ]),

                        WebsitePublishing::formSection('cars'),

                        Forms\Components\Section::make('Internal notes')
                            ->icon('heroicon-o-lock-closed')
                            ->collapsible()
                            ->schema([
                                Forms\Components\Textarea::make('notes')
                                    ->hiddenLabel()
                                    ->placeholder('Only visible to your team.')
                                    ->rows(4),
                            ]),

                        Forms\Components\Section::make()
                            ->hiddenOn('create')
                            ->schema([
                                Forms\Components\Placeholder::make('created_at')
                                    ->label('Added')
                                    ->content(fn (?Car $record): string => $record?->created_at?->diffForHumans() ?? '-'),
                                Forms\Components\Placeholder::make('updated_at')
                                    ->label('Last updated')
                                    ->content(fn (?Car $record): string => $record?->updated_at?->diffForHumans() ?? '-'),
                            ]),
                    ]),
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
                Tables\Columns\TextColumn::make('registration_number')
                    ->label('Plate')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
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
