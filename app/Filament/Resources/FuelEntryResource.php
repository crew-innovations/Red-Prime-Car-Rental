<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FuelEntryResource\Pages;
use App\Models\Car;
use App\Models\FuelEntry;
use App\Support\Access;
use App\Support\Fuel\Fuel;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Accounts › Fuel (5 Nov, S5). Fill-ups from drivers' phones wait here for Accounts: confirm (the
 * fuel expense is made on the car, and on the booking when the car was out) or reject with a
 * reason. Accounts / Admin can add a fill-up themselves (confirmed at once). Fuel prices per month
 * and the refuel fee (Admin) are set from the header. Nothing is deleted.
 */
class FuelEntryResource extends Resource
{
    protected static ?string $model = FuelEntry::class;

    protected static ?string $slug = 'fuel';

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Accounts & reports';

    protected static ?string $navigationLabel = 'Fuel';

    protected static ?string $modelLabel = 'fill-up';

    protected static ?string $pluralModelLabel = 'fuel';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return ! Access::isDriverOnly() && Access::allows('manage-finance');
    }

    public static function canCreate(): bool
    {
        return false; // "Add fill-up" on the list (one place that confirms and makes the expense)
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = FuelEntry::where('status', 'waiting')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Fill-ups waiting for Accounts';
    }

    /** The fill-up form, shared by "Add fill-up" here and on a car page. */
    public static function entryForm(?int $carId = null): array
    {
        return [
            Forms\Components\Select::make('car_id')->label('Car')
                ->options(fn () => Car::query()->orderBy('name')->get()->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->registration_number ? ' · '.$c->registration_number : '')]))
                ->searchable()->required()->default($carId)->hidden($carId !== null),
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\DateTimePicker::make('filled_at')->label('When')->native(false)->seconds(false)->displayFormat('d M Y, H:i')
                    ->default(now())->maxDate(now())->required(),
                Forms\Components\TextInput::make('litres')->numeric()->minValue(0.1)->maxValue(500)->step(0.01)->suffix('L')->required(),
                Forms\Components\TextInput::make('amount')->numeric()->minValue(0.1)->maxValue(20000)->step(0.01)->prefix('AED')->required(),
                Forms\Components\TextInput::make('km')->label('Odometer')->numeric()->minValue(0)->suffix('km')
                    ->helperText('Needed for km per litre.'),
                Forms\Components\Select::make('fuel_grade')->label('Grade')->options(Fuel::GRADES)->native(false)
                    ->placeholder('The car\'s grade'),
                Forms\Components\TextInput::make('station')->maxLength(120),
            ]),
            Forms\Components\Toggle::make('full_tank')->label('Filled to full')->default(true)
                ->helperText('Full-to-full fill-ups give the km per litre.'),
            Forms\Components\FileUpload::make('receipt')->label('Receipt')
                ->disk('private')->directory('tmp')->visibility('private')
                ->acceptedFileTypes(['image/*', 'application/pdf'])->maxSize(10240),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('filled_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['car', 'user', 'booking', 'confirmer']))
            ->columns([
                Tables\Columns\TextColumn::make('filled_at')->label('When')->dateTime('d M, H:i')->sortable()
                    ->description(fn (FuelEntry $r) => $r->user?->name),
                Tables\Columns\TextColumn::make('car.name')->label('Car')->weight(FontWeight::SemiBold)->searchable()->wrap()
                    ->description(fn (FuelEntry $r) => collect([$r->car?->registration_number, $r->booking?->reference])->filter()->implode(' · ') ?: null),
                Tables\Columns\TextColumn::make('litres')->numeric(2)->suffix(' L')->alignEnd()
                    ->description(fn (FuelEntry $r) => ($r->full_tank ? 'full' : 'part').($r->km ? ' · '.number_format($r->km).' km' : '')),
                Tables\Columns\TextColumn::make('amount')->money('AED')->alignEnd()->sortable()
                    ->description(fn (FuelEntry $r) => self::priceCheck($r)),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => FuelEntry::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'confirmed' => 'success', 'rejected' => 'danger', default => 'warning' })
                    ->description(fn (FuelEntry $r) => $r->status === 'rejected' ? $r->reject_reason : ($r->confirmer ? 'by '.$r->confirmer->name : null))
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('car_id')->label('Car')->relationship('car', 'name')->searchable(),
                Tables\Filters\Filter::make('month')
                    ->form([Forms\Components\Select::make('month')->options(fn () => collect(range(0, 11))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => now()->startOfMonth()->subMonths($i)->format('F Y')]))->native(false)])
                    ->query(fn (Builder $query, array $data) => $query->when($data['month'] ?? null, fn ($q, $m) => $q->whereBetween('filled_at', [\Illuminate\Support\Carbon::parse($m.'-01')->startOfMonth(), \Illuminate\Support\Carbon::parse($m.'-01')->endOfMonth()]))),
            ])
            ->actions([
                Tables\Actions\Action::make('receipt')->label('Receipt')->icon('heroicon-o-photo')->color('gray')->iconButton()->tooltip('Receipt')
                    ->visible(fn (FuelEntry $r) => (bool) $r->receipt_path)
                    ->url(fn (FuelEntry $r) => \Illuminate\Support\Facades\Storage::disk('private')->temporaryUrl($r->receipt_path, now()->addMinutes(30)), true),
                Tables\Actions\Action::make('confirm')->label('Confirm')->icon('heroicon-o-check')->color('success')->button()->size('sm')
                    ->visible(fn (FuelEntry $r) => $r->status === 'waiting')
                    ->action(function (FuelEntry $r) {
                        Fuel::confirm($r, auth()->user());
                        Notification::make()->title('Confirmed: fuel expense added to '.$r->car?->name)->success()->send();
                    }),
                Tables\Actions\Action::make('reject')->label('Reject')->icon('heroicon-o-x-mark')->color('danger')->iconButton()->tooltip('Reject')
                    ->visible(fn (FuelEntry $r) => $r->status === 'waiting')
                    ->modalHeading('Reject this fill-up?')
                    ->modalDescription('No expense is made. The driver sees your reason.')
                    ->form([Forms\Components\TextInput::make('reason')->label('Why')->required()->maxLength(255)
                        ->placeholder('e.g. receipt unreadable, not our car, amount does not match')])
                    ->action(function (FuelEntry $r, array $data) {
                        Fuel::reject($r, $data['reason'], auth()->user());
                        Notification::make()->title('Rejected')->success()->send();
                    }),
                Tables\Actions\Action::make('expense')->label('Expense')->icon('heroicon-o-banknotes')->color('gray')->iconButton()->tooltip('Open the expense')
                    ->visible(fn (FuelEntry $r) => (bool) $r->transaction_id)
                    ->url(fn (FuelEntry $r) => TransactionResource::getUrl('edit', ['record' => $r->transaction_id])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('confirmAll')->label('Confirm selected')->icon('heroicon-o-check')->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Each waiting fill-up becomes a fuel expense on its car.')
                    ->action(function (Collection $records) {
                        $n = 0;
                        foreach ($records->where('status', 'waiting') as $r) {
                            Fuel::confirm($r, auth()->user());
                            $n++;
                        }
                        Notification::make()->title($n.' fill-up(s) confirmed')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('No fill-ups here')
            ->emptyStateDescription('Drivers add fill-ups from their phone (Fuel). You can add one with "Add fill-up".');
    }

    /** "AED 2.85/L" and a warning when it is far from this month's price for that grade. */
    protected static function priceCheck(FuelEntry $r): ?string
    {
        $per = $r->pricePerLitre();
        if (! $per) {
            return null;
        }
        [$set] = Fuel::price($r->fuel_grade ?: $r->car?->fuel_grade, $r->filled_at);
        $off = $set && abs($per - $set) / $set > 0.1;

        return 'AED '.number_format($per, 2).'/L'.($off ? ' ⚠ set price '.number_format($set, 2) : '');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFuelEntries::route('/'),
        ];
    }
}
