<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TollTripResource\Pages;
use App\Models\Booking;
use App\Models\Car;
use App\Models\TollTrip;
use App\Support\Access;
use App\Support\Tolls\Tolls;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Fines & tolls › Salik, Darb & parking (4 Nov, section 3). Each trip is matched to the booking
 * that had the car and goes on its rolled-up line; trips nobody pays become a company cost.
 */
class TollTripResource extends Resource
{
    protected static ?string $model = TollTrip::class;

    protected static ?string $slug = 'toll-trips';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Fines & tolls';

    protected static ?string $navigationLabel = 'Salik, Darb & parking';

    protected static ?string $modelLabel = 'toll trip';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-fines');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = TollTrip::where('status', 'new')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->columns(3)->schema([
            Forms\Components\Select::make('toll_type')->label('Type')->options(TollTrip::TYPES)->required()->native(false)->default('salik'),
            Forms\Components\DateTimePicker::make('passed_at')->label('Date and time')->seconds(false)->required(),
            Forms\Components\TextInput::make('amount')->numeric()->minValue(0.5)->prefix('AED')->required(),
            Forms\Components\Select::make('car_id')->label('Car')->options(fn () => Car::query()->orderBy('name')->get()->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->registration_number ? ' · '.$c->registration_number : '')]))->searchable()->native(false),
            Forms\Components\TextInput::make('plate')->maxLength(40),
            Forms\Components\TextInput::make('gate')->label('Gate / zone')->maxLength(120),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['car', 'booking.customer']))
            ->defaultSort('passed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('passed_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('toll_type')->label('Type')->badge()->formatStateUsing(fn ($state) => TollTrip::TYPES[$state] ?? $state)->color('gray'),
                Tables\Columns\TextColumn::make('gate')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('car.name')->label('Car')->placeholder(fn (TollTrip $r) => $r->tag_no ?: $r->plate ?: '—')
                    ->description(fn (TollTrip $r) => $r->tag_no ? 'Tag '.$r->tag_no : $r->plate)->searchable(['plate', 'tag_no']),
                Tables\Columns\TextColumn::make('amount')->money('AED')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => TollTrip::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => TollTrip::COLORS[$state] ?? 'gray')
                    ->description(fn (TollTrip $r) => $r->status === 'new' ? $r->match_note : null),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')->description(fn (TollTrip $r) => $r->booking?->customer?->name)
                    ->url(fn (TollTrip $r) => $r->booking ? BookingResource::getUrl('edit', ['record' => $r->booking]) : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('toll_type')->label('Type')->options(TollTrip::TYPES),
                Tables\Filters\SelectFilter::make('car_id')->label('Car')->relationship('car', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('charge')->label('Add to booking')->icon('heroicon-o-banknotes')
                    ->visible(fn (TollTrip $r) => $r->status === 'matched' && $r->booking)
                    ->action(function (TollTrip $r) {
                        $n = Tolls::chargeBooking($r->booking);
                        Notification::make()->title($n.' trip(s) added to '.$r->booking->reference)->success()->send();
                    }),
                Tables\Actions\Action::make('give')->label('Give to a booking')->icon('heroicon-o-link')
                    ->visible(fn (TollTrip $r) => $r->status === 'new')
                    ->form([Forms\Components\Select::make('booking_id')->label('Booking')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Booking::query()->where('reference', 'like', '%'.$search.'%')->limit(20)->pluck('reference', 'id'))])
                    ->action(function (TollTrip $r, array $data) {
                        $b = Booking::findOrFail($data['booking_id']);
                        $r->forceFill(['booking_id' => $b->id, 'status' => 'matched', 'match_note' => 'Given to '.$b->reference.' by '.auth()->user()->name])->save();
                        if (Tolls::autoCharge()) {
                            Tolls::chargeBooking($b);
                        }
                        Notification::make()->title('Given to '.$b->reference)->success()->send();
                    }),
                Tables\Actions\Action::make('match')->label('Match again')->icon('heroicon-o-arrow-path')->iconButton()
                    ->visible(fn (TollTrip $r) => in_array($r->status, ['new', 'matched'], true))
                    ->action(fn (TollTrip $r) => Tolls::match($r)),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('company')->label('Company cost')->icon('heroicon-o-building-office')
                    ->form([Forms\Components\TextInput::make('note')->label('Why')->required()->maxLength(200)->helperText('E.g. our own use, a driver\'s delivery drive, test drive.')])
                    ->action(function (Collection $records, array $data) {
                        $t = Tolls::companyCost($records, $data['note']);
                        Notification::make()->title($t ? 'Expense of AED '.number_format((float) $t->amount, 2).' recorded' : 'Nothing to do')->success()->send();
                    }),
                Tables\Actions\BulkAction::make('matchAll')->label('Match again')->icon('heroicon-o-arrow-path')
                    ->action(function (Collection $records) {
                        $bookings = [];
                        foreach ($records as $r) {
                            $r = Tolls::match($r);
                            if ($r->status === 'matched') {
                                $bookings[$r->booking_id] = true;
                            }
                        }
                        if (Tolls::autoCharge()) {
                            foreach (array_keys($bookings) as $id) {
                                Tolls::chargeBooking(Booking::find($id));
                            }
                        }
                        Notification::make()->title(count($bookings).' booking(s) found')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTollTrips::route('/')];
    }
}
