<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\Car;
use App\Models\Setting;
use App\Support\Drivers\DriverJobs;
use App\Support\Drivers\DriverWork;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\ActionSize;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * One delivery or collection, on the driver's phone: where, when, who, the car,
 * and big buttons for the next step (accept, on the way, arrived, hand over /
 * collect with the inspection, report damage). Only the job's own driver can open it.
 */
class DriverJob extends Page
{
    protected static string $view = 'filament.pages.driver-job';

    protected static ?string $slug = 'my-jobs/{job}';

    protected static bool $shouldRegisterNavigation = false;

    public BookingJob $record;

    public static function getRelativeRouteName(): string
    {
        return 'driver-job';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('Driver');
    }

    public function mount(int|string $job): void
    {
        $record = BookingJob::with(['booking.bookable', 'booking.customer'])->find($job);
        abort_unless($record && (int) $record->driver_id === (int) auth()->id(), 404);
        $this->record = $record;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->typeLabel().' · '.$this->record->booking?->reference;
    }

    public function getBreadcrumbs(): array
    {
        return [MyJobs::getUrl() => 'My jobs', 'Job'];
    }

    public function booking(): ?Booking
    {
        return $this->record->booking;
    }

    public function showCustomerPhone(): bool
    {
        return (bool) Setting::read('driver_sees_customer_phone', true);
    }

    public function showInstructions(): bool
    {
        return (bool) Setting::read('driver_sees_instructions', true);
    }

    public function customerPhone(): ?string
    {
        $c = $this->booking()?->customer;

        return $this->showCustomerPhone() ? ($c?->phone ?: $c?->whatsapp) : null;
    }

    public function whatsappUrl(): ?string
    {
        $c = $this->booking()?->customer;

        return $this->showCustomerPhone() ? Phone::whatsappUrl($c?->whatsapp ?: $c?->phone) : null;
    }

    public function mapUrl(): ?string
    {
        return $this->record->address ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->record->address) : null;
    }

    /** Next steps the driver may set (from Business settings), in order. */
    public function nextSteps(): array
    {
        if (! $this->record->isOpen()) {
            return [];
        }
        $allowed = (array) Setting::read('driver_steps', []);
        $order = array_keys(\App\Models\BookingJob::DRIVER_STEPS);
        $current = array_search($this->record->status, $order, true);
        $current = $current === false ? -1 : $current;

        return collect($order)
            ->filter(fn ($step, $i) => $i > $current && in_array($step, $allowed, true))
            ->values()->all();
    }

    public function setStep(string $step): void
    {
        $this->record->refresh();
        DriverJobs::step($this->record, $step, auth()->user());
        Notification::make()->title(\App\Models\BookingJob::DRIVER_STEPS[$step].' · saved')->success()->send();
    }

    /** Inspection fields shared by handover and collection. */
    protected function inspectionFields(string $stage): array
    {
        $booking = $this->booking();
        $minKm = $stage === 'handover'
            ? ($booking?->bookable instanceof Car ? (int) $booking->bookable->mileage : 0)
            : (int) ($booking?->delivery_km ?? 0);
        $list = (array) Setting::read($stage.'_checklist', []);
        $minPhotos = max(0, (int) Setting::read($stage.'_min_photos', 0));

        return array_values(array_filter([
            Forms\Components\TextInput::make('km')
                ->label('Odometer')
                ->numeric()->minValue($minKm)->suffix('km')
                ->extraInputAttributes(['inputmode' => 'numeric'])
                ->helperText($minKm ? 'Last reading '.number_format($minKm).' km' : null)
                ->required((bool) Setting::read($stage.'_require_km', true)),
            Forms\Components\ToggleButtons::make('fuel')
                ->label('Fuel level')
                ->options(Booking::FUEL_LEVELS)
                ->inline()
                ->required((bool) Setting::read($stage.'_require_fuel', true)),
            $stage === 'return' ? Forms\Components\ToggleButtons::make('condition')
                ->label('Condition')
                ->options(Booking::CONDITIONS)
                ->colors(['good' => 'success', 'minor' => 'warning', 'damaged' => 'danger'])
                ->default('good')
                ->live()
                ->required() : null,
            $stage === 'return' ? Forms\Components\Textarea::make('damage')
                ->label('What damage did you find?')
                ->rows(3)
                ->required()
                ->visible(fn (Forms\Get $get): bool => in_array($get('condition'), ['minor', 'damaged'], true)) : null,
            $list ? Forms\Components\CheckboxList::make('checklist')
                ->label('Checklist')
                ->options(array_combine($list, $list))
                ->bulkToggleable()
                ->rules(Setting::read('checklist_all_required') ? ['array', 'size:'.count($list)] : ['array'])
                ->validationMessages(['size' => 'Tick every item on the checklist.']) : null,
            Forms\Components\FileUpload::make('photos')
                ->label($minPhotos ? "Photos (at least {$minPhotos})" : 'Photos')
                ->helperText('Take new photos or pick from your gallery. Wait until each one shows as uploaded.')
                ->disk('private')->directory('tmp')->visibility('private')
                ->image()->multiple()->maxFiles(20)->maxSize(10240)
                ->imageResizeMode('contain')->imageResizeTargetWidth('1600')->imageResizeTargetHeight('1600')
                ->panelLayout('grid')
                ->minFiles($minPhotos)
                ->required($minPhotos > 0),
            Forms\Components\Textarea::make('notes')->label('Notes')->rows(2),
        ]));
    }

    public function handoverAction(): Action
    {
        return Action::make('handover')
            ->label('Hand over the car')
            ->icon('heroicon-o-key')
            ->size(ActionSize::ExtraLarge)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => DriverWork::canHandOver($this->record))
            ->modalHeading('Hand over · '.($this->booking()?->bookable?->name ?? 'car'))
            ->modalDescription('Check the car with the customer, then save. The office records any payment.')
            ->modalSubmitActionLabel('Save handover')
            ->closeModalByClickingAway(false)
            ->form(fn (): array => $this->inspectionFields('handover'))
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, fn () => DriverWork::handOver($this->record->refresh(), auth()->user(), $data));
                Notification::make()->title('Handover saved')->body('The office has been told. Drive safe.')->success()->send();
                $this->record->refresh();
            });
    }

    public function collectAction(): Action
    {
        return Action::make('collect')
            ->label('Collect the car')
            ->icon('heroicon-o-arrow-uturn-left')
            ->size(ActionSize::ExtraLarge)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => DriverWork::canCollect($this->record))
            ->modalHeading('Collection check · '.($this->booking()?->bookable?->name ?? 'car'))
            ->modalDescription('Record how the car came back. The office completes the return and any charges.')
            ->modalSubmitActionLabel('Save collection')
            ->closeModalByClickingAway(false)
            ->form(fn (): array => $this->inspectionFields('return'))
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, fn () => DriverWork::collect($this->record->refresh(), auth()->user(), $data));
                Notification::make()->title('Collection saved')->body('The office will complete the return.')->success()->send();
                $this->record->refresh();
            });
    }

    public function startCharterAction(): Action
    {
        return Action::make('startCharter')
            ->label('Start the charter')
            ->icon('heroicon-o-play')
            ->size(ActionSize::ExtraLarge)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => DriverWork::canStartCharter($this->record))
            ->modalHeading('Start · '.($this->booking()?->bookable?->name ?? 'yacht'))
            ->modalDescription('Guests are on board and you are leaving. The office records any payment.')
            ->modalSubmitActionLabel('Start now')
            ->form([
                Forms\Components\TextInput::make('guests')->label('Guests on board')->numeric()->minValue(1)
                    ->default(fn () => $this->booking()?->guests),
                Forms\Components\Textarea::make('notes')->label('Notes')->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, fn () => DriverWork::startCharter($this->record->refresh(), auth()->user(), $data));
                Notification::make()->title('Charter started')->body('The office has been told. Have a good trip.')->success()->send();
                $this->record->refresh();
            });
    }

    public function finishCharterAction(): Action
    {
        return Action::make('finishCharter')
            ->label('Finish the charter')
            ->icon('heroicon-o-flag')
            ->size(ActionSize::ExtraLarge)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => DriverWork::canFinishCharter($this->record))
            ->modalHeading('Finish · '.($this->booking()?->bookable?->name ?? 'yacht'))
            ->modalDescription('Back at the marina. The office closes the booking and any charges.')
            ->modalSubmitActionLabel('Save')
            ->form([
                Forms\Components\Textarea::make('notes')->label('Notes (fuel, extra time, anything to know)')->rows(3),
                Forms\Components\Textarea::make('damage')->label('Damage or problems (if any)')->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, fn () => DriverWork::finishCharter($this->record->refresh(), auth()->user(), $data));
                Notification::make()->title('Charter finished')->body('The office will close it.')->success()->send();
                $this->record->refresh();
            });
    }

    public function damageAction(): Action
    {
        return Action::make('damage')
            ->label('Report damage or a problem')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->outlined()
            ->size(ActionSize::Large)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => (bool) Setting::read('driver_can_report_damage', true) && ($this->record->isOpen() || $this->record->done_at?->gt(now()->subDay())))
            ->modalHeading('Report damage or a problem')
            ->modalDescription('The office reviews it. You do not need to agree any charge with the customer.')
            ->modalSubmitActionLabel('Send report')
            ->closeModalByClickingAway(false)
            ->form([
                Forms\Components\Textarea::make('description')->label('What happened?')->rows(4)->required()->maxLength(2000),
                Forms\Components\FileUpload::make('photos')
                    ->label('Photos')
                    ->disk('private')->directory('tmp')->visibility('private')
                    ->image()->multiple()->maxFiles(12)->maxSize(10240)
                    ->imageResizeMode('contain')->imageResizeTargetWidth('1600')->imageResizeTargetHeight('1600')
                    ->panelLayout('grid'),
            ])
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, fn () => DriverWork::reportDamage($this->record->refresh(), auth()->user(), (string) $data['description'], (array) ($data['photos'] ?? [])));
                Notification::make()->title('Report sent to the office')->success()->send();
            });
    }

    /** Server checks failed: tell the driver plainly and keep the form open with what they typed. */
    protected function runSafely(Action $action, \Closure $work): void
    {
        try {
            $work();
        } catch (ValidationException $e) {
            Notification::make()->title('Not saved yet')->body(collect($e->errors())->flatten()->implode(' '))->danger()->persistent()->send();
            $action->halt();
        }
    }
}
