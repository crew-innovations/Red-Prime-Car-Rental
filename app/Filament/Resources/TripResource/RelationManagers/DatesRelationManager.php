<?php

namespace App\Filament\Resources\TripResource\RelationManagers;

use App\Models\TripDate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\CarbonPeriod;

class DatesRelationManager extends RelationManager
{
    protected static string $relationship = 'dates';

    protected static ?string $title = 'Available dates';

    protected static ?string $icon = 'heroicon-o-calendar-days';

    public function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\DatePicker::make('date')
                    ->required()
                    ->native(false)
                    ->displayFormat('D, d M Y')
                    ->minDate(today()),
                Forms\Components\TimePicker::make('start_time')
                    ->seconds(false)
                    ->native(false)
                    ->default(fn () => $this->getOwnerRecord()->start_time),
                Forms\Components\TextInput::make('available_slots')
                    ->label('Seats available')
                    ->numeric()
                    ->minValue(0)
                    ->default(fn () => $this->getOwnerRecord()->guest_capacity),
                Forms\Components\Toggle::make('is_available')
                    ->label('Open for booking')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->date('D, d M Y')
                    ->description(fn (TripDate $record): ?string => $record->date->isPast() ? 'Past' : $record->date->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_time')
                    ->label('Starts')
                    ->time('H:i'),
                Tables\Columns\TextColumn::make('available_slots')
                    ->label('Seats')
                    ->placeholder('-'),
                Tables\Columns\ToggleColumn::make('is_available')
                    ->label('Open'),
            ])
            ->defaultSort('date')
            ->filters([
                Tables\Filters\Filter::make('upcoming')
                    ->label('Upcoming only')
                    ->default()
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('date', '>=', today())),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addRange')
                    ->label('Add several dates')
                    ->icon('heroicon-o-calendar')
                    ->color('gray')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from')
                                ->required()
                                ->native(false)
                                ->minDate(today())
                                ->default(today()),
                            Forms\Components\DatePicker::make('until')
                                ->required()
                                ->native(false)
                                ->afterOrEqual('from')
                                ->default(today()->addMonth()),
                        ]),
                        Forms\Components\CheckboxList::make('weekdays')
                            ->label('Runs on')
                            ->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'])
                            ->default([0, 1, 2, 3, 4, 5, 6])
                            ->columns(7)
                            ->required(),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TimePicker::make('start_time')
                                ->seconds(false)
                                ->native(false)
                                ->default(fn () => $this->getOwnerRecord()->start_time),
                            Forms\Components\TextInput::make('available_slots')
                                ->label('Seats per date')
                                ->numeric()
                                ->minValue(0)
                                ->default(fn () => $this->getOwnerRecord()->guest_capacity),
                        ]),
                    ])
                    ->action(function (array $data): void {
                        $trip = $this->getOwnerRecord();
                        $weekdays = array_map('intval', $data['weekdays']);
                        $existing = $trip->dates()->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
                        $added = 0;

                        foreach (CarbonPeriod::create($data['from'], $data['until']) as $day) {
                            if (! in_array($day->dayOfWeek, $weekdays, true) || in_array($day->toDateString(), $existing, true)) {
                                continue;
                            }

                            $trip->dates()->create([
                                'date' => $day->toDateString(),
                                'start_time' => $data['start_time'] ?? null,
                                'available_slots' => $data['available_slots'] ?? null,
                                'is_available' => true,
                            ]);
                            $added++;
                        }

                        Notification::make()
                            ->title($added ? "{$added} dates added" : 'No new dates to add')
                            ->body($added ? null : 'Those dates already exist or none matched the chosen days.')
                            ->{$added ? 'success' : 'warning'}()
                            ->send();
                    }),
                Tables\Actions\CreateAction::make()->label('Add date'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
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
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No dates yet')
            ->emptyStateDescription('Use "Add several dates" to create a whole month in one go.');
    }
}
