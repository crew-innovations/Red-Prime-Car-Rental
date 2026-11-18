<?php

namespace App\Filament\Resources\CarResource\RelationManagers;

use App\Filament\Resources\FuelEntryResource;
use App\Models\FuelEntry;
use App\Support\Access;
use App\Support\Fuel\Fuel;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * "Fuel" tab on a car (5 Nov, S5): this month's litres and spend, km per litre, cost per km,
 * a warning when the car suddenly uses much more fuel, and its fill-ups.
 */
class FuelEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'fuelEntries';

    protected static ?string $title = 'Fuel';

    protected static ?string $icon = 'heroicon-o-beaker';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-finance');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $n = FuelEntry::where('car_id', $ownerRecord->getKey())->where('status', 'waiting')->count();

        return $n ? (string) $n : null;
    }

    public function table(Table $table): Table
    {
        $car = $this->getOwnerRecord();

        return $table->defaultSort('filled_at', 'desc')
            ->heading(new HtmlString(view('filament.fuel.car-stats', ['car' => $car, 's' => Fuel::stats($car)])->render()))
            ->columns([
                Tables\Columns\TextColumn::make('filled_at')->label('When')->dateTime('d M Y, H:i'),
                Tables\Columns\TextColumn::make('user.name')->label('By')->placeholder('—'),
                Tables\Columns\TextColumn::make('km')->label('Odometer')->numeric()->placeholder('—'),
                Tables\Columns\TextColumn::make('litres')->numeric(2)->suffix(' L')
                    ->description(fn (FuelEntry $r) => $r->full_tank ? 'full tank' : 'part'),
                Tables\Columns\TextColumn::make('amount')->money('AED'),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => FuelEntry::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'confirmed' => 'success', 'rejected' => 'danger', default => 'warning' }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('add')->label('Add fill-up')->icon('heroicon-o-plus')
                    ->modalHeading('Add fill-up · '.$car->name)
                    ->modalWidth('2xl')
                    ->form(FuelEntryResource::entryForm($car->id))
                    ->action(function (array $data, Tables\Actions\Action $action) use ($car) {
                        try {
                            Fuel::record(['car_id' => $car->id] + $data, auth()->user());
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                            $action->halt();
                        }
                        Notification::make()->title('Fill-up added')->success()->send();
                    }),
                Tables\Actions\Action::make('all')->label('All fuel')->color('gray')->url(fn () => FuelEntryResource::getUrl('index', ['activeTab' => 'all'])),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')->label('Confirm')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (FuelEntry $r) => $r->status === 'waiting')
                    ->action(fn (FuelEntry $r) => Fuel::confirm($r, auth()->user())),
            ])
            ->emptyStateHeading('No fill-ups yet')
            ->emptyStateDescription('Drivers add them from their phone; Accounts confirm them.');
    }
}
