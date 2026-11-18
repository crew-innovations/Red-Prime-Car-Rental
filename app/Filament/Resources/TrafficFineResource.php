<?php

namespace App\Filament\Resources;

use App\Enums\FineStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\TrafficFineResource\Pages;
use App\Models\Car;
use App\Models\TrafficFine;
use App\Support\Access;
use App\Support\Fines\Fines;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Fines & tolls › Traffic fines (4 Nov, section 2). Imported from the authority's statement or
 * typed in; matched to the booking that had the car; charged to the customer (pass-through,
 * not revenue) with the admin fee; paid to the authority. Nothing is deleted: wrong ones are
 * waived or marked disputed, with a note.
 */
class TrafficFineResource extends Resource
{
    protected static ?string $model = TrafficFine::class;

    protected static ?string $slug = 'traffic-fines';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Fines & tolls';

    protected static ?string $navigationLabel = 'Traffic fines';

    protected static ?string $modelLabel = 'traffic fine';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-fines');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && in_array($record->status, [FineStatus::New, FineStatus::Matched, FineStatus::Disputed], true);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = TrafficFine::where('status', FineStatus::New)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\Section::make('The fine')->columns(3)->schema([
                Forms\Components\Select::make('authority')->options(array_combine(TrafficFine::AUTHORITIES, TrafficFine::AUTHORITIES))->required()->native(false),
                Forms\Components\TextInput::make('ticket_no')->label('Ticket / fine number')->required()->maxLength(80)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule->where('authority', $get('authority'))),
                Forms\Components\DateTimePicker::make('offence_at')->label('Date and time of the offence')->seconds(false)->required(),
                Forms\Components\TextInput::make('plate')->label('Plate number')->maxLength(40)->helperText('Used to find the car when none is picked.'),
                Forms\Components\Select::make('car_id')->label('Car')->options(fn () => Car::query()->orderBy('name')->get()->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->registration_number ? ' · '.$c->registration_number : '')]))->searchable()->native(false),
                Forms\Components\TextInput::make('amount')->numeric()->minValue(1)->prefix('AED')->required(),
                Forms\Components\TextInput::make('offence')->maxLength(255)->columnSpan(2),
                Forms\Components\TextInput::make('black_points')->numeric()->minValue(0)->maxValue(24),
                Forms\Components\TextInput::make('location')->maxLength(255)->columnSpan(2),
                Forms\Components\FileUpload::make('path')->label('Fine notice (PDF or photo)')->disk('private')->directory('fines')->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/*'])->maxSize(8192),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['car', 'booking', 'customer', 'driver']))
            ->defaultSort('offence_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('offence_at')->label('When')->dateTime('d M Y H:i')->sortable()
                    ->description(fn (TrafficFine $r) => $r->authority),
                Tables\Columns\TextColumn::make('ticket_no')->label('Ticket')->searchable()->weight(FontWeight::SemiBold)
                    ->description(fn (TrafficFine $r) => $r->offence),
                Tables\Columns\TextColumn::make('car.name')->label('Car')->placeholder(fn (TrafficFine $r) => $r->plate ?: '—')
                    ->description(fn (TrafficFine $r) => $r->car?->registration_number ?? $r->plate)->searchable(['plate']),
                Tables\Columns\TextColumn::make('amount')->money('AED')->sortable()
                    ->description(fn (TrafficFine $r) => (float) $r->admin_fee > 0 ? '+ fee AED '.number_format((float) $r->admin_fee, 2) : null),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->description(fn (TrafficFine $r) => $r->status === FineStatus::New ? $r->match_note : ($r->dispute_note ? \Illuminate\Support\Str::limit($r->dispute_note, 60) : null)),
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')
                    ->description(fn (TrafficFine $r) => $r->customer?->name ?? ($r->driver ? 'Driver: '.$r->driver->name : null))
                    ->url(fn (TrafficFine $r) => $r->booking ? BookingResource::getUrl('edit', ['record' => $r->booking]) : null),
                Tables\Columns\IconColumn::make('paid_at')->label('Paid to authority')->boolean()->getStateUsing(fn (TrafficFine $r) => (bool) $r->paid_at)
                    ->tooltip(fn (TrafficFine $r) => $r->paid_at ? $r->paid_at->format('d M Y').($r->paid_reference ? ' · '.$r->paid_reference : '') : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('authority')->options(array_combine(TrafficFine::AUTHORITIES, TrafficFine::AUTHORITIES)),
                Tables\Filters\SelectFilter::make('car_id')->label('Car')->relationship('car', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('charge')->label('Charge to customer')->icon('heroicon-o-banknotes')->color('primary')
                    ->visible(fn (TrafficFine $r) => $r->status === FineStatus::Matched && $r->customer_id)
                    ->modalDescription(fn (TrafficFine $r) => 'Adds AED '.number_format((float) $r->amount, 2).' to '.$r->booking?->reference.' ('.$r->customer?->name.') as a fine paid on their behalf (not revenue). The customer is told.')
                    ->fillForm(fn (TrafficFine $r) => ['admin_fee' => Fines::adminFee((float) $r->amount) ?: null])
                    ->form([
                        Forms\Components\TextInput::make('admin_fee')->label('Admin fee')->numeric()->minValue(0)->prefix('AED')
                            ->helperText('From Business settings › Fines & tolls (empty there = no fee). Changing it needs "Change prices".')
                            ->disabled(fn () => ! Access::allows('adjust-prices'))->dehydrated(),
                    ])
                    ->action(fn (TrafficFine $r, array $data) => self::run(fn () => Fines::charge($r, Access::allows('adjust-prices') ? (float) ($data['admin_fee'] ?? 0) : null), 'Fine added to '.$r->booking?->reference)),
                Tables\Actions\Action::make('pay')->label('Paid to authority')->icon('heroicon-o-building-library')->color('gray')
                    ->visible(fn (TrafficFine $r) => ! $r->paid_at && ! in_array($r->status, [FineStatus::Disputed, FineStatus::RechargedSupplier], true) && Access::allows('manage-finance'))
                    ->form([
                        Forms\Components\Select::make('method')->options(PaymentMethod::class)->required()->native(false),
                        Forms\Components\TextInput::make('reference')->label('Payment reference')->maxLength(80),
                        Forms\Components\DatePicker::make('date')->default(today())->required(),
                    ])
                    ->action(fn (TrafficFine $r, array $data) => self::run(fn () => Fines::payAuthority($r, $data['method'] instanceof \BackedEnum ? $data['method']->value : $data['method'], $data['reference'] ?? null, $data['date']), 'Payment to the authority recorded')),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('match')->label('Match again')->icon('heroicon-o-arrow-path')
                        ->visible(fn (TrafficFine $r) => in_array($r->status, [FineStatus::New, FineStatus::Matched, FineStatus::Disputed], true))
                        ->form([Forms\Components\TextInput::make('reason')->required()->maxLength(200)->helperText('For example: the plate was corrected, or the return time was changed.')])
                        ->action(fn (TrafficFine $r, array $data) => self::run(fn () => Fines::match($r, $data['reason']), 'Matched again')),
                    Tables\Actions\Action::make('booking')->label('Give to a booking')->icon('heroicon-o-link')
                        ->visible(fn (TrafficFine $r) => in_array($r->status, [FineStatus::New, FineStatus::Matched], true))
                        ->form([
                            Forms\Components\Select::make('booking_id')->label('Booking')->required()->searchable()
                                ->getSearchResultsUsing(fn (string $search) => \App\Models\Booking::query()->where('reference', 'like', '%'.$search.'%')->limit(20)->pluck('reference', 'id')),
                            Forms\Components\TextInput::make('reason')->required()->maxLength(200),
                        ])
                        ->action(function (TrafficFine $r, array $data) {
                            $b = \App\Models\Booking::findOrFail($data['booking_id']);
                            $r->forceFill(['booking_id' => $b->id, 'customer_id' => $b->customer_id, 'car_id' => $r->car_id ?? ($b->bookable_type === 'car' ? $b->bookable_id : null),
                                'status' => FineStatus::Matched, 'match_note' => 'Given to '.$b->reference.' by '.auth()->user()->name])->save();
                            \App\Models\AuditLog::note($r, 'fine', 'Given to '.$b->reference.': '.$data['reason']);
                            Notification::make()->title('Fine given to '.$b->reference)->success()->send();
                        }),
                    Tables\Actions\Action::make('status')->label('Disputed / waived / company cost')->icon('heroicon-o-flag')
                        ->visible(fn (TrafficFine $r) => $r->status !== FineStatus::Collected)
                        ->form([
                            Forms\Components\Select::make('to')->label('Mark as')->required()->native(false)
                                ->options(collect([FineStatus::Disputed, FineStatus::Waived, FineStatus::CompanyCost, FineStatus::RechargedSupplier])->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])),
                            Forms\Components\Textarea::make('note')->required()->maxLength(500)->rows(2)
                                ->helperText('A charge already on the booking is taken off (voided) with this note.'),
                        ])
                        ->action(fn (TrafficFine $r, array $data) => self::run(fn () => Fines::setStatus($r, FineStatus::from($data['to']), $data['note']), 'Saved')),
                    Tables\Actions\Action::make('notice')->label('View the notice')->icon('heroicon-o-eye')
                        ->visible(fn (TrafficFine $r) => (bool) $r->path)->url(fn (TrafficFine $r) => $r->viewUrl())->openUrlInNewTab(),
                    Tables\Actions\EditAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('chargeAll')->label('Charge selected to customers')->icon('heroicon-o-banknotes')
                    ->requiresConfirmation()->modalDescription('Each matched fine goes on its customer\'s booking with the admin fee from Business settings.')
                    ->action(function (Collection $records) {
                        $n = 0;
                        foreach ($records as $r) {
                            if ($r->status === FineStatus::Matched && $r->customer_id) {
                                $n += rescue(fn () => (bool) Fines::charge($r), false, false) ? 1 : 0;
                            }
                        }
                        Notification::make()->title($n.' fine(s) charged')->success()->send();
                    }),
                Tables\Actions\BulkAction::make('matchAll')->label('Match again')->icon('heroicon-o-arrow-path')
                    ->action(function (Collection $records) {
                        $n = 0;
                        foreach ($records as $r) {
                            if (in_array($r->status, [FineStatus::New, FineStatus::Matched], true)) {
                                $n += Fines::match($r, 'Matched again (selection)')->status === FineStatus::Matched ? 1 : 0;
                            }
                        }
                        Notification::make()->title($n.' matched to a customer')->success()->send();
                    }),
            ]);
    }

    /** Runs an action and shows the reason if it can't be done. */
    public static function run(\Closure $work, string $ok): void
    {
        try {
            $work();
            Notification::make()->title($ok)->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title('Not done')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrafficFines::route('/'),
            'create' => Pages\CreateTrafficFine::route('/create'),
            'edit' => Pages\EditTrafficFine::route('/{record}/edit'),
        ];
    }
}
