<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Car;
use App\Models\Category;
use App\Models\Trip;
use App\Models\Yacht;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Website sections (Cars, Yachts, Yacht Trips). Switching one off hides it from
 * the website without touching any records (Clause 4.6).
 */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Website';

    protected static ?string $navigationLabel = 'Website sections';

    protected static ?string $modelLabel = 'website section';

    protected static ?int $navigationSort = 1;

    /** Category slug => [model, public path] */
    public const SECTIONS = [
        Category::CARS => [Car::class, 'cars'],
        Category::YACHTS => [Yacht::class, 'yachts'],
        Category::YACHT_TRIPS => [Trip::class, 'yacht-trips'],
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Name shown on the website menu')
                ->required()
                ->maxLength(100),
            Forms\Components\Toggle::make('is_enabled')
                ->label('Visible on website'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $count = fn (Category $record, bool $publishedOnly = false): int => isset(self::SECTIONS[$record->slug])
            ? self::SECTIONS[$record->slug][0]::query()->when($publishedOnly, fn ($q) => $q->where('is_published', true))->count()
            : 0;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Category $record): string => '/'.(self::SECTIONS[$record->slug][1] ?? $record->slug)),
                Tables\Columns\TextColumn::make('items')
                    ->label('Items')
                    ->state(fn (Category $record): string => $count($record, true).' published of '.$count($record)),
                Tables\Columns\ToggleColumn::make('is_enabled')
                    ->label('Visible on website')
                    ->afterStateUpdated(fn (Category $record, bool $state) => Notification::make()
                        ->title($state ? "{$record->name} is now visible on the website" : "{$record->name} is hidden from the website")
                        ->body($state ? null : 'Nothing was deleted. Switch it back on at any time.')
                        ->success()
                        ->send()),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->paginated(false)
            ->actions([
                Tables\Actions\EditAction::make()->label('Rename'),
            ])
            ->description('Turn a whole section on or off on the public website. Records stay safe in the CRM.');
    }

    public static function canCreate(): bool
    {
        return false; // the three sections are fixed by the website structure
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCategories::route('/'),
        ];
    }
}
