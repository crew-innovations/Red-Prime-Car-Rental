<?php

namespace App\Filament\Resources\PackageResource\RelationManagers;

use App\Models\PackageDeparture;
use Carbon\CarbonPeriod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Scheduled dates a package runs, with places per date. */
class DeparturesRelationManager extends RelationManager
{
    protected static string $relationship = 'departures';

    protected static ?string $title = 'Departures';

    protected static ?string $icon = 'heroicon-o-calendar-days';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->booking_mode === 'departures' || $ownerRecord->departures()->exists();
    }

    public function form(Form $form): Form
    {
        $owner = $this->getOwnerRecord();

        return $form
            ->columns(3)
            ->schema([
                Forms\Components\DatePicker::make('start_date')
                    ->label('Starts')
                    ->required()
                    ->native(false)
                    ->displayFormat('D, d M Y')
                    ->live()
                    ->afterStateUpdated(function (Forms\Set $set, ?string $state) use ($owner) {
                        if ($state && $owner->duration_days > 1) {
                            $set('end_date', Carbon::parse($state)->addDays($owner->duration_days - 1)->toDateString());
                        }
                    }),
                Forms\Components\DatePicker::make('end_date')
                    ->label('Ends')
                    ->native(false)
                    ->displayFormat('D, d M Y')
                    ->afterOrEqual('start_date'),
                Forms\Components\TimePicker::make('start_time')
                    ->label('Start time')
                    ->seconds(false)
                    ->native(false),
                Forms\Components\TextInput::make('capacity')
                    ->label('Places')
                    ->helperText($owner->default_capacity ? 'Empty = '.$owner->default_capacity.' (package default).' : 'Empty = no limit.')
                    ->numeric()
                    ->minValue(0),
                Forms\Components\TextInput::make('price')
                    ->label('Price for this date')
                    ->helperText('Empty = package price.')
                    ->numeric()
                    ->minValue(0)
                    ->prefix($owner->currency ?: 'AED'),
                Forms\Components\Toggle::make('is_available')
                    ->label('Open for booking')
                    ->default(true)
                    ->inline(false),
                Forms\Components\TextInput::make('note')
                    ->label('Note (internal)')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('start_date')
            ->columns([
                Tables\Columns\TextColumn::make('start_date')
                    ->label('Starts')
                    ->date('D, d M Y')
                    ->description(fn (PackageDeparture $record): ?string => $record->end_date ? 'until '.$record->end_date->format('D, d M') : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_time')->label('Time')->time('H:i')->placeholder('-'),
                Tables\Columns\TextColumn::make('places')
                    ->label('Places')
                    ->state(fn (PackageDeparture $record): string => $record->seats() === null ? 'No limit' : (string) $record->seats()),
                Tables\Columns\TextColumn::make('booked')
                    ->label('Booked')
                    ->state(fn (PackageDeparture $record): int => $record->bookedGuests())
                    ->badge()
                    ->color(fn (PackageDeparture $record, int $state): string => $record->seats() !== null && $state >= $record->seats() ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('price')
                    ->label('Price')
                    ->state(fn (PackageDeparture $record): ?string => $record->price !== null ? number_format((float) $record->price) : null)
                    ->placeholder('Package price'),
                Tables\Columns\ToggleColumn::make('is_available')
                    ->label('Open')
                    ->disabled(fn (): bool => $this->isReadOnly()),
            ])
            ->defaultSort('start_date')
            ->filters([
                Tables\Filters\Filter::make('upcoming')
                    ->label('Upcoming only')
                    ->default()
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('start_date', '>=', today())),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addRange')
                    ->label('Add several dates')
                    ->authorize('manage-fleet')
                    ->icon('heroicon-o-calendar')
                    ->color('gray')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from')->required()->native(false)->minDate(today())->default(today()),
                            Forms\Components\DatePicker::make('until')->required()->native(false)->afterOrEqual('from')->default(today()->addMonths(2)),
                        ]),
                        Forms\Components\CheckboxList::make('weekdays')
                            ->label('Starts on')
                            ->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'])
                            ->default([5])
                            ->columns(7)
                            ->required(),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TimePicker::make('start_time')->seconds(false)->native(false),
                            Forms\Components\TextInput::make('capacity')->label('Places per date')->numeric()->minValue(0),
                        ]),
                    ])
                    ->action(function (array $data): void {
                        $package = $this->getOwnerRecord();
                        $weekdays = array_map('intval', $data['weekdays']);
                        $existing = $package->departures()->pluck('start_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
                        $added = 0;

                        foreach (CarbonPeriod::create($data['from'], $data['until']) as $day) {
                            if (! in_array($day->dayOfWeek, $weekdays, true) || in_array($day->toDateString(), $existing, true)) {
                                continue;
                            }
                            $package->departures()->create([
                                'start_date' => $day->toDateString(),
                                'end_date' => $package->duration_days > 1 ? $day->copy()->addDays($package->duration_days - 1)->toDateString() : null,
                                'start_time' => $data['start_time'] ?? null,
                                'capacity' => $data['capacity'] ?? null,
                                'is_available' => true,
                            ]);
                            $added++;
                        }

                        Notification::make()
                            ->title($added ? "{$added} departures added" : 'No new departures to add')
                            ->{$added ? 'success' : 'warning'}()
                            ->send();
                    }),
                Tables\Actions\CreateAction::make()->label('Add departure'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton()
                    ->hidden(fn (PackageDeparture $record): bool => $record->bookings()->exists()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('close')
                        ->label('Close for booking')
                        ->icon('heroicon-o-lock-closed')
                        ->action(fn (Collection $records) => $records->each->update(['is_available' => false]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('open')
                        ->label('Open for booking')
                        ->icon('heroicon-o-lock-open')
                        ->action(fn (Collection $records) => $records->each->update(['is_available' => true]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('No departures yet')
            ->emptyStateDescription('Add the dates this package runs. Use "Add several dates" for a whole season.');
    }

    public function isReadOnly(): bool
    {
        return ! \App\Support\Access::allows('manage-fleet');
    }
}
