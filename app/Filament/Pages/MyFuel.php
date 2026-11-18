<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\DriverLocale;
use App\Models\FuelEntry;
use App\Support\Fuel\Fuel;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Driver › Fuel (5 Nov, S5): a driver adds a fill-up from the phone (km, litres, amount, receipt
 * photo). It is only a claim: Accounts confirm it, and only then is it a company cost. The driver
 * sees whether each one was confirmed, never anything else about money.
 */
class MyFuel extends Page
{
    use DriverLocale;

    protected static string $view = 'filament.pages.my-fuel';

    protected static ?string $slug = 'driver/fuel';

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?int $navigationSort = -18;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('Driver');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->isDriverOnly();
    }

    public static function getNavigationLabel(): string
    {
        \App\Support\Drivers\DriverLang::apply(auth()->user());

        return __('driver.nav_fuel');
    }

    public function getTitle(): string
    {
        return __('driver.fuel_title');
    }

    public function mount(): void
    {
        if (request()->boolean('add')) {
            $this->mountAction('addFuel');
        }
    }

    public function mine(): Collection
    {
        return FuelEntry::query()->where('user_id', auth()->id())->with('car')->latest('filled_at')->limit(30)->get();
    }

    /** The car of the driver's nearest job, so the form starts filled in. */
    protected function likelyCar(): ?int
    {
        $job = \App\Models\BookingJob::query()->where('driver_id', auth()->id())
            ->whereBetween('scheduled_at', [now()->subDays(2), now()->addDays(2)])
            ->with('booking')->get()
            ->filter(fn ($j) => $j->booking?->bookable_type === 'car')
            ->sortBy(fn ($j) => abs($j->scheduled_at->diffInMinutes(now())))->first();

        return $job?->booking?->bookable_id;
    }

    public function addFuelAction(): Action
    {
        return Action::make('addFuel')
            ->label(__('driver.fuel_add'))
            ->icon('heroicon-o-plus')
            ->size('lg')
            ->modalHeading(__('driver.fuel_add'))
            ->modalDescription(__('driver.fuel_add_desc'))
            ->modalSubmitActionLabel(__('driver.save'))
            ->modalWidth('md')
            ->fillForm(fn () => ['car_id' => $this->likelyCar(), 'full_tank' => true, 'client_id' => (string) Str::uuid()])
            ->form(function (): array {
                $cars = Fuel::carsFor(auth()->user());

                return [
                    Forms\Components\Hidden::make('client_id'),
                    Forms\Components\Placeholder::make('none')->hiddenLabel()->content(__('driver.fuel_no_cars'))->visible($cars->isEmpty()),
                    Forms\Components\Select::make('car_id')->label(__('driver.fuel_car'))
                        ->options($cars->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->registration_number ? ' · '.$c->registration_number : '')]))
                        ->native(false)->required(),
                    Forms\Components\TextInput::make('km')->label(__('driver.fuel_km'))->numeric()->minValue(0)->suffix('km')
                        ->extraInputAttributes(['inputmode' => 'numeric']),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('litres')->label(__('driver.fuel_litres'))->numeric()->minValue(0.1)->maxValue(500)->step(0.01)
                            ->extraInputAttributes(['inputmode' => 'decimal'])->required(),
                        Forms\Components\TextInput::make('amount')->label(__('driver.fuel_amount'))->numeric()->minValue(0.1)->maxValue(20000)->step(0.01)
                            ->extraInputAttributes(['inputmode' => 'decimal'])->required(),
                    ]),
                    Forms\Components\Toggle::make('full_tank')->label(__('driver.fuel_full_tank')),
                    Forms\Components\FileUpload::make('receipt')->label(__('driver.fuel_receipt'))
                        ->disk('private')->directory('tmp')->visibility('private')
                        ->image()->maxSize(10240)
                        ->imageResizeMode('contain')->imageResizeTargetWidth('1600')->imageResizeTargetHeight('1600')
                        ->required((bool) \App\Models\Setting::read('fuel_receipt_required', true)),
                    Forms\Components\TextInput::make('station')->label(__('driver.fuel_station'))->maxLength(120),
                ];
            })
            ->action(function (array $data, Action $action): void {
                $allowed = Fuel::carsFor(auth()->user())->pluck('id')->all();
                if (! in_array((int) ($data['car_id'] ?? 0), $allowed, true)) {
                    Notification::make()->title(__('driver.fuel_pick_car'))->danger()->send();
                    $action->halt();
                }
                try {
                    Fuel::record($data, auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();
                }
                Notification::make()->title(__('driver.fuel_saved'))->success()->send();
            });
    }
}
