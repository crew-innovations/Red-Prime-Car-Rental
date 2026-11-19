<?php

namespace App\Filament\Resources\RentalContractResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Resources\RentalContractResource;
use App\Models\Booking;
use App\Models\RentalContract;
use App\Support\Contracts\Contracts;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * 19 Nov (Bilal: "independent rental contract creation inside the Contracts tab, without opening
 * Booking"). The same steps as a new car booking (customer pop-up with documents, car, dates, price,
 * delivery, deposit), plus a Contract step (type, company details, extra drivers). Saving makes the
 * booking behind it and the contract, so customers, cars, money, fines and handover stay connected.
 */
class CreateRentalContract extends CreateBooking
{
    protected static string $resource = RentalContractResource::class;

    protected ?RentalContract $contract = null;

    public function getModel(): string
    {
        return Booking::class;
    }

    public function getTitle(): string
    {
        return 'New rental contract';
    }

    public function mount(): void
    {
        $this->service = 'car';
        parent::mount();
    }

    public function getSteps(): array
    {
        $steps = parent::getSteps();
        $last = array_pop($steps);

        return [...$steps, $this->contractStep(), $last];
    }

    protected function contractStep(): Step
    {
        $date = fn (string $n, string $l) => Forms\Components\DatePicker::make($n)->label($l)->native(false)->displayFormat('d M Y');

        return Step::make('Contract')->icon('heroicon-o-document-text')->description('Type and drivers')->schema([
            Forms\Components\Section::make('Contract')->columns(2)->schema([
                Forms\Components\ToggleButtons::make('contract.type')->label('Contract type')->options(RentalContract::TYPES)->inline()->live()
                    ->helperText('Leave it empty to work it out from the customer (company or not) and the rental type (monthly or not).')
                    ->dehydrated(false)->columnSpanFull(),
                Forms\Components\TextInput::make('contract.lpo_number')->label('Purchase order (LPO) no.')->maxLength(60)->dehydrated(false)
                    ->visible(fn (Get $get): bool => $get('contract.type') === 'company'),
                Forms\Components\TextInput::make('contract.company_contact')->label('Company contact')->maxLength(255)->dehydrated(false)
                    ->visible(fn (Get $get): bool => $get('contract.type') === 'company'),
                Forms\Components\Select::make('contract.billing_period')->label('Invoices')->native(false)->dehydrated(false)
                    ->placeholder('Automatic (monthly contracts: one per month)')
                    ->options(['daily' => 'One invoice for the rental', 'monthly' => 'One invoice per month']),
                Forms\Components\Toggle::make('contract.auto_renew')->label('Renew automatically each month')->inline(false)->dehydrated(false)
                    ->visible(fn (Get $get): bool => $get('contract.type') === 'monthly'),
                Forms\Components\Textarea::make('contract.notes')->label('Notes on the contract (optional)')->rows(2)->maxLength(1000)->dehydrated(false)->columnSpanFull(),
            ]),
            Forms\Components\Section::make('Additional drivers')
                ->description('The customer is the main driver. Add anyone else who will drive, with their licence.')
                ->schema([
                    Forms\Components\Repeater::make('contract.drivers')->hiddenLabel()->dehydrated(false)->defaultItems(0)->addActionLabel('Add a driver')
                        ->columns(3)->collapsible()->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->schema([
                            Forms\Components\TextInput::make('name')->label('Full name')->required()->maxLength(255),
                            Forms\Components\TextInput::make('phone')->tel()->maxLength(30),
                            $date('date_of_birth', 'Date of birth')->required()->maxDate(today()),
                            Forms\Components\TextInput::make('id_number')->label('Emirates ID / passport no.')->required()->maxLength(40),
                            Forms\Components\TextInput::make('licence_number')->label('Licence number')->required()->maxLength(40),
                            $date('licence_expires_on', 'Licence expiry')->required(),
                            Forms\Components\TextInput::make('licence_country')->label('Licence issued in')->maxLength(60),
                            Forms\Components\TextInput::make('charge')->label('Charge')->numeric()->minValue(0)->prefix('AED')
                                ->default(fn () => \App\Models\Setting::read('additional_driver_charge')),
                        ]),
                ]),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['contract']); // contract details are saved on the contract, not the booking

        return parent::mutateFormDataBeforeCreate($data);
    }

    protected function afterCreate(): void
    {
        parent::afterCreate(); // advance payment, override log (same as a new booking)

        /** @var Booking $booking */
        $booking = $this->getRecord();
        $in = (array) ($this->data['contract'] ?? []);
        $this->contract = Contracts::make($booking->fresh(), collect($in)->only(['type', 'lpo_number', 'company_contact', 'billing_period', 'auto_renew', 'notes'])->filter(fn ($v) => $v !== null && $v !== '')->all());
        foreach ((array) ($in['drivers'] ?? []) as $d) {
            if (blank($d['name'] ?? null)) {
                continue;
            }
            try {
                Contracts::addDriver($this->contract->fresh(), $d);
            } catch (ValidationException $e) {
                Notification::make()->warning()->title('Driver '.$d['name'].' not added')->body(collect($e->errors())->flatten()->first())->persistent()->send();
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->contract ? RentalContractResource::getUrl('view', ['record' => $this->contract]) : BookingResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Contract '.($this->contract?->number ?? '').' made';
    }
}
