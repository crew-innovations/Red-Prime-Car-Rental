<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\Car;
use App\Models\Setting;
use App\Support\Drivers\DriverJobs;
use App\Support\Drivers\DriverWork;
use App\Support\Phone;
use App\Support\Uploads\CameraUpload;
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
    use \App\Filament\Concerns\DriverLocale;

    protected static string $view = 'filament.pages.driver-job';

    protected static ?string $slug = 'driver/jobs/{job}';

    protected static bool $shouldRegisterNavigation = false;

    public BookingJob $record;

    /** Opened from "Decline" on the job list: the decline form opens straight away. */
    public $defaultAction = null;

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
        if (request()->query('decline') && \App\Support\Drivers\DriverFlow::canDecline($record)) {
            $this->defaultAction = 'decline';
        }
    }

    /** Settings the phone script needs (steps queue, location sharing). */
    public function phoneConfig(): array
    {
        $share = \App\Support\Transfers\Tracking::sharesLocation($this->record); // 6 Nov: limousine trips share location (H-48)

        return [
            'job' => $this->record->id,
            'share' => $share,
            'interval' => max(15, (int) Setting::read('location_interval_seconds', 60)),
        ];
    }

    /** Driver ↔ office conversation for this booking (28 Oct-2). */
    public function messageOffice()
    {
        abort_unless($this->record->booking && (int) $this->record->driver_id === (int) auth()->id(), 404);

        return redirect(Messages::getUrl(['thread' => \App\Support\Chat\Chat::driverThread($this->record->booking, auth()->user())->id]));
    }

    public function getTitle(): string|Htmlable
    {
        return __('driver.type_'.$this->record->typeKey()).' · '.$this->record->booking?->reference;
    }

    public function getBreadcrumbs(): array
    {
        return [MyJobs::getUrl() => __('driver.nav_jobs'), __('driver.job')];
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
        $b = $this->booking();
        if ($this->record->type === 'transfer' && $b) {
            return \App\Support\Maps\GoogleMaps::directionsLink($b->pickup_location, $b->dropoff_location, collect((array) $b->stops)->pluck('address')->filter()->all());
        }

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
        Notification::make()->title(__('driver.step_'.$step).' · '.__('driver.saved'))->success()->send();
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

        $signature = (string) Setting::read('handover_signature', 'optional');

        return array_values(array_filter([
            Forms\Components\TextInput::make('km')
                ->label(__('driver.odometer'))
                ->numeric()->minValue($minKm)->suffix('km')
                ->extraInputAttributes(['inputmode' => 'numeric'])
                ->helperText($minKm ? __('driver.last_reading', ['km' => number_format($minKm)]) : null)
                ->required((bool) Setting::read($stage.'_require_km', true)),
            Forms\Components\ToggleButtons::make('fuel')
                ->label(__('driver.fuel_level'))
                ->options(\App\Support\Fuel\Fuel::gaugeOptions(__('driver.fuel_empty'), __('driver.fuel_full')))
                ->inline()
                ->extraAttributes(['class' => 'rp-fuel-gauge'])
                ->required((bool) Setting::read($stage.'_require_fuel', true)),
            $stage === 'return' ? Forms\Components\ToggleButtons::make('condition')
                ->label(__('driver.condition'))
                ->options(Booking::CONDITIONS)
                ->colors(['good' => 'success', 'minor' => 'warning', 'damaged' => 'danger'])
                ->default('good')
                ->live()
                ->required() : null,
            $stage === 'return' ? Forms\Components\Textarea::make('damage')
                ->label(__('driver.what_damage'))
                ->rows(3)
                ->required()
                ->visible(fn (Forms\Get $get): bool => in_array($get('condition'), ['minor', 'damaged'], true)) : null,
            $list ? Forms\Components\CheckboxList::make('checklist')
                ->label(__('driver.checklist'))
                ->options(array_combine($list, $list))
                ->bulkToggleable()
                ->rules(Setting::read('checklist_all_required') ? ['array', 'size:'.count($list)] : ['array'])
                ->validationMessages(['size' => __('driver.tick_all')]) : null,
            ...CameraUpload::withScan(
                Forms\Components\FileUpload::make('photos')
                    ->label($minPhotos ? __('driver.photos_min', ['n' => $minPhotos]) : __('driver.photos'))
                    ->helperText(__('driver.photos_help'))
                    ->disk('private')->directory('tmp')->visibility('private')
                    ->image()->multiple()->maxFiles(20)->maxSize(10240)
                    ->imageResizeMode('contain')->imageResizeTargetWidth('1600')->imageResizeTargetHeight('1600')
                    ->panelLayout('grid')
                    ->minFiles($minPhotos)
                    ->required($minPhotos > 0),
                'photos'
            ),
            Forms\Components\Textarea::make('notes')->label(__('driver.notes'))->rows(2),
            // 4 Nov (15): the customer signs on the phone (Business settings: off / optional / required).
            $signature !== 'off' ? Forms\Components\TextInput::make('signer')->label(__('driver.signer'))->maxLength(120)
                ->default(fn () => $this->booking()?->customer?->name) : null,
            $signature !== 'off' ? Forms\Components\ViewField::make('signature')->label(__('driver.signature').($signature === 'required' ? ' *' : ''))
                ->view('filament.driver.signature-pad')
                ->rules($signature === 'required' ? ['required', 'string'] : ['nullable', 'string'])
                ->validationMessages(['required' => __('driver.signature_needed')]) : null,
        ]));
    }

    /** 25 Nov: CRM driver signs the rental contract on their phone (independent of the hirer). */
    public function signContractAction(): Action
    {
        return Action::make('signContract')
            ->label(__('driver.sign_contract'))
            ->icon('heroicon-o-pencil-square')
            ->size(ActionSize::ExtraLarge)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => $this->canSignContract())
            ->modalHeading(fn (): string => __('driver.sign_contract').' · '.($this->contract()?->number ?? ''))
            ->modalDescription(__('driver.sign_contract_desc'))
            ->modalSubmitActionLabel(__('driver.sign_contract_save'))
            ->closeModalByClickingAway(false)
            ->form([
                Forms\Components\TextInput::make('signer')->label(__('driver.signer'))->required()->maxLength(120)
                    ->default(fn () => auth()->user()?->name),
                Forms\Components\ViewField::make('signature')->label(__('driver.signature').' *')
                    ->view('filament.driver.signature-pad')
                    ->rules(['required', 'string'])
                    ->validationMessages(['required' => __('driver.signature_needed')]),
                Forms\Components\Checkbox::make('agree')->label(__('driver.sign_contract_agree'))->accepted()
                    ->validationMessages(['accepted' => __('driver.sign_contract_agree')]),
            ])
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, function () use ($data) {
                    $png = DriverWork::signaturePng($data['signature'] ?? null);
                    if (! $png) {
                        throw ValidationException::withMessages(['data.signature' => __('driver.signature_needed')]);
                    }
                    \App\Support\Contracts\Contracts::signDriver($this->contract()->fresh(), $png, $data['signer'] ?? auth()->user()?->name, 'driver');
                });
                Notification::make()->title(__('driver.sign_contract_saved'))->success()->send();
                $this->record->refresh();
            });
    }

    public function contract(): ?\App\Models\RentalContract
    {
        $b = $this->booking();

        return $b ? \App\Models\RentalContract::where('booking_id', $b->id)->first() : null;
    }

    public function canSignContract(): bool
    {
        $c = $this->contract();

        return $c
            && $this->record->isOpen()
            && $c->isEditable()
            && ! $c->driver_signed_at
            && in_array($this->record->type, ['delivery', 'collection'], true);
    }

    public function handoverAction(): Action
    {
        return Action::make('handover')
            ->label(__('driver.handover'))
            ->icon('heroicon-o-key')
            ->size(ActionSize::ExtraLarge)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => DriverWork::canHandOver($this->record))
            ->modalHeading(__('driver.handover').' · '.($this->booking()?->bookable?->name ?? ''))
            ->modalDescription(__('driver.handover_desc'))
            ->modalSubmitActionLabel(__('driver.save_handover'))
            ->closeModalByClickingAway(false)
            ->form(fn (): array => $this->inspectionFields('handover'))
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, fn () => DriverWork::handOver($this->record->refresh(), auth()->user(), $data));
                Notification::make()->title(__('driver.handover_saved'))->body(__('driver.handover_saved_body'))->success()->send();
                $this->record->refresh();
            });
    }

    public function collectAction(): Action
    {
        return Action::make('collect')
            ->label(__('driver.collect'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->size(ActionSize::ExtraLarge)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => DriverWork::canCollect($this->record))
            ->modalHeading(__('driver.collect').' · '.($this->booking()?->bookable?->name ?? ''))
            ->modalDescription(__('driver.collect_desc'))
            ->modalSubmitActionLabel(__('driver.save_collection'))
            ->closeModalByClickingAway(false)
            ->form(fn (): array => $this->inspectionFields('return'))
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, fn () => DriverWork::collect($this->record->refresh(), auth()->user(), $data));
                Notification::make()->title(__('driver.collection_saved'))->body(__('driver.collection_saved_body'))->success()->send();
                $this->record->refresh();
            });
    }

    public function startCharterAction(): Action
    {
        return Action::make('startCharter')
            ->label(fn (): string => $this->record->type === 'transfer' ? __('driver.picked_up') : __('driver.start_charter'))
            ->icon('heroicon-o-play')
            ->size(ActionSize::ExtraLarge)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => DriverWork::canStartCharter($this->record))
            ->modalHeading(fn (): string => ($this->record->type === 'transfer' ? __('driver.picked_up') : __('driver.start_charter')).($this->booking()?->bookable?->name ? ' · '.$this->booking()->bookable->name : ''))
            ->modalDescription(fn (): string => $this->record->type === 'transfer' ? __('driver.start_desc_transfer') : __('driver.start_desc_charter'))
            ->modalSubmitActionLabel(__('driver.start_now'))
            ->form([
                Forms\Components\TextInput::make('guests')->label(fn (): string => $this->record->type === 'transfer' ? __('driver.passengers') : __('driver.guests'))->numeric()->minValue(1)
                    ->default(fn () => $this->booking()?->guests),
                Forms\Components\Textarea::make('notes')->label(__('driver.notes'))->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, fn () => DriverWork::startCharter($this->record->refresh(), auth()->user(), $data));
                Notification::make()->title(__('driver.started'))->body(__('driver.started_body'))->success()->send();
                $this->record->refresh();
            });
    }

    public function finishCharterAction(): Action
    {
        return Action::make('finishCharter')
            ->label(fn (): string => $this->record->type === 'transfer' ? __('driver.dropped_off') : __('driver.finish_charter'))
            ->icon('heroicon-o-flag')
            ->size(ActionSize::ExtraLarge)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => DriverWork::canFinishCharter($this->record))
            ->modalHeading(fn (): string => ($this->record->type === 'transfer' ? __('driver.dropped_off') : __('driver.finish_charter')).($this->booking()?->bookable?->name ? ' · '.$this->booking()->bookable->name : ''))
            ->modalDescription(fn (): string => $this->record->type === 'transfer' ? __('driver.finish_desc_transfer') : __('driver.finish_desc_charter'))
            ->modalSubmitActionLabel(__('driver.save'))
            ->form([
                Forms\Components\Textarea::make('notes')->label(fn (): string => $this->record->type === 'transfer' ? __('driver.finish_notes_transfer') : __('driver.finish_notes_charter'))->rows(3),
                Forms\Components\Textarea::make('damage')->label(__('driver.problems_if_any'))->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, fn () => DriverWork::finishCharter($this->record->refresh(), auth()->user(), $data));
                Notification::make()->title(__('driver.finished'))->body(__('driver.finished_body'))->success()->send();
                $this->record->refresh();
            });
    }

    public function damageAction(): Action
    {
        return Action::make('damage')
            ->label(__('driver.report'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->outlined()
            ->size(ActionSize::Large)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => (bool) Setting::read('driver_can_report_damage', true) && ($this->record->isOpen() || $this->record->done_at?->gt(now()->subDay())))
            ->modalHeading(__('driver.report'))
            ->modalDescription(__('driver.report_desc'))
            ->modalSubmitActionLabel(__('driver.send_report'))
            ->closeModalByClickingAway(false)
            ->fillForm(fn () => ['kind' => 'damage', 'client_action_id' => (string) \Illuminate\Support\Str::uuid()])
            ->form([
                Forms\Components\Hidden::make('client_action_id'),
                Forms\Components\ToggleButtons::make('kind')->label(__('driver.what_kind'))->inline()->live()
                    ->options(['damage' => __('driver.kind_damage'), 'accident' => __('driver.kind_accident')])
                    ->colors(['damage' => 'warning', 'accident' => 'danger'])->required(),
                Forms\Components\Textarea::make('description')->label(__('driver.what_happened'))->rows(4)->required()->maxLength(2000),
                Forms\Components\TextInput::make('police_report_no')->label(__('driver.police_no'))->maxLength(60)
                    ->helperText(__('driver.police_help'))
                    ->visible(fn (Forms\Get $get): bool => $get('kind') === 'accident'),
                Forms\Components\ViewField::make('location')->label(__('driver.where'))->view('filament.driver.location-field'),
                Forms\Components\Hidden::make('lat'),
                Forms\Components\Hidden::make('lng'),
                ...CameraUpload::withScan(
                    Forms\Components\FileUpload::make('photos')
                        ->label(__('driver.photos'))
                        ->disk('private')->directory('tmp')->visibility('private')
                        ->image()->multiple()->maxFiles(12)->maxSize(10240)
                        ->imageResizeMode('contain')->imageResizeTargetWidth('1600')->imageResizeTargetHeight('1600')
                        ->panelLayout('grid'),
                    'photos'
                ),
            ])
            ->action(function (array $data, Action $action): void {
                $this->runSafely($action, fn () => DriverWork::reportDamage($this->record->refresh(), auth()->user(), (string) $data['description'], (array) ($data['photos'] ?? []),
                    \Illuminate\Support\Arr::only($data, ['kind', 'police_report_no', 'location', 'lat', 'lng', 'client_action_id'])));
                Notification::make()->title(__('driver.report_sent'))->body(($data['kind'] ?? '') === 'accident' ? __('driver.accident_body') : null)->success()->send();
            });
    }

    public function declineAction(): Action
    {
        return Action::make('decline')
            ->label(__('driver.decline'))
            ->color('gray')
            ->size(ActionSize::Large)
            ->extraAttributes(['class' => 'rp-dv-bigbtn'])
            ->visible(fn (): bool => \App\Support\Drivers\DriverFlow::canDecline($this->record))
            ->modalHeading(__('driver.decline_title'))
            ->modalDescription(__('driver.decline_desc'))
            ->modalSubmitActionLabel(__('driver.decline'))
            ->form([
                Forms\Components\Radio::make('why')->label(__('driver.decline_reason'))->required()
                    ->options(collect(['busy', 'sick', 'far', 'car', 'other'])->mapWithKeys(fn ($k) => [$k => __('driver.reason_'.$k)])->all())->live(),
                Forms\Components\TextInput::make('other')->label(__('driver.reason_write'))->maxLength(200)
                    ->required(fn (Forms\Get $get): bool => $get('why') === 'other')->visible(fn (Forms\Get $get): bool => $get('why') === 'other'),
            ])
            ->action(function (array $data, Action $action): void {
                $reason = $data['why'] === 'other' ? (string) $data['other'] : __('driver.reason_'.$data['why'], [], 'en');
                $this->runSafely($action, fn () => \App\Support\Drivers\DriverFlow::run($this->record, auth()->user(), 'decline', ['reason' => $reason]));
                Notification::make()->title(__('driver.declined'))->body(__('driver.declined_body'))->success()->send();
                $this->redirect(MyJobs::getUrl());
            });
    }

    /** Server checks failed: tell the driver plainly and keep the form open with what they typed. */
    protected function runSafely(Action $action, \Closure $work): void
    {
        try {
            $work();
        } catch (ValidationException $e) {
            Notification::make()->title(__('driver.not_saved'))->body(collect($e->errors())->flatten()->implode(' '))->danger()->persistent()->send();
            $action->halt();
        }
    }
}
