<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Enums\DriveMode;
use App\Enums\ServiceType;
use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Yacht;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class CreateBooking extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard; // 6 Nov (S4 / C.2): 4 steps

    protected static string $resource = BookingResource::class;

    public function getSteps(): array
    {
        return \App\Filament\Support\Wizardize::steps(BookingResource::class, $this, [
            ['Customer & car', 'heroicon-o-user', ['Customer & service'], 'Who, and what they want'],
            ['Dates', 'heroicon-o-calendar', ['Journey', 'Dates & place', 'Rental agreement'], 'When and where'],
            ['Price', 'heroicon-o-banknotes', ['Price', 'Payment'], 'Worked out for you'],
            ['Check & save', 'heroicon-o-check-circle', ['Status', 'Team & notes'], 'Anything else'],
        ]);
    }

    protected function hasSkippableSteps(): bool
    {
        return true; // staff can jump to any step; saving still checks every field
    }

    /** Prefill from a lead, a customer, or a car / yacht / trip page. */
    #[Url]
    public ?string $lead = null;

    #[Url]
    public ?string $customer = null;

    #[Url]
    public ?string $service = null;

    #[Url]
    public ?string $item = null;

    /**
     * 19 Nov (Bilal, item 7): one id per opened form. A second click or a repeated request with the same
     * id never makes a second booking: it opens the one already saved.
     */
    #[Locked]
    public string $saveToken = '';

    public function mount(): void
    {
        $this->saveToken = (string) Str::uuid();
        parent::mount();
    }

    /**
     * Saving, made reliable (19 Nov, item 7):
     * - repeated clicks / requests do not make duplicates (lock on the form id);
     * - a problem found while saving (car already booked, driver busy…) is shown as a message and the
     *   form jumps to the field, instead of nothing visible happening on the last step;
     * - an unexpected error is reported to the log and shown as a clear message; nothing half-saved stays.
     */
    public function create(bool $another = false): void
    {
        $key = 'booking-create:'.auth()->id().':'.($this->saveToken ?: Str::uuid());
        if ($done = Cache::get($key.':done')) {
            $this->redirect($this->getResource()::getUrl('edit', ['record' => $done]));

            return;
        }
        $lock = Cache::lock($key, 60);
        if (! $lock->get()) {
            Notification::make()->info()->title('Saving… please wait')->body('This booking is already being saved.')->send();

            return;
        }
        Booking::$savedNotice = null;
        try {
            parent::create($another);
            if ($this->record?->exists) {
                Cache::put($key.':done', $this->record->getKey(), now()->addHours(6));
            }
        } catch (ValidationException $e) {
            $this->dispatch('form-validation-error', livewireId: $this->getId());
            Notification::make()->danger()->title('The booking was not saved')
                ->body(collect($e->errors())->flatten()->unique()->take(4)->implode(' '))
                ->persistent()->send();

            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $ref = strtoupper(Str::random(6));
            \Illuminate\Support\Facades\Log::error('Booking create failed ['.$ref.']: '.$e->getMessage(), ['user' => auth()->id()]);
            Notification::make()->danger()->title('The booking was not saved')
                ->body('Something went wrong on our side and nothing was saved. Please try again. If it happens again, tell the admin (code '.$ref.').')
                ->persistent()->send();
        } finally {
            $lock->release();
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        $b = $this->getRecord();
        $n = Notification::make()->success()->title('Booking '.($b->reference ?? '').' saved')
            ->body(($b->customer?->name ? $b->customer->name.' · ' : '').$b->status?->getLabel());
        if (Booking::$savedNotice) {
            $n = Notification::make()->warning()->title('Booking '.($b->reference ?? '').' saved')->body(Booking::$savedNotice)->persistent();
            Booking::$savedNotice = null;
        }

        return $n;
    }

    protected function afterFill(): void
    {
        $lead = Lead::find($this->lead);
        $service = $this->service ?: $lead?->service_type?->value;
        if ($service === ServiceType::Yacht->value) {
            $service = ServiceType::Trip->value; // private charters are no longer offered
        }
        if (! in_array($service, array_map(fn (ServiceType $s) => $s->value, ServiceType::offered()), true)) {
            $service = ServiceType::Car->value;
        }

        $data = ['service_type' => $service];

        if ($lead) {
            $data['lead_id'] = $lead->id;
            $data['customer_id'] = $lead->customer_id;
            $data['assigned_to'] = $lead->assigned_to ?? auth()->id();
            $data['guests'] = $lead->guests;
            if ($lead->interested_in_type === $service) {
                $data['bookable_id'] = $lead->interested_in_id;
            }
            // Website requests can name the exact trip date / package departure.
            if ($service === ServiceType::Trip->value && $lead->trip_date_id) {
                $data['trip_date_id'] = $lead->trip_date_id;
            }
            if ($service === ServiceType::Package->value && $lead->package_departure_id) {
                $data['package_departure_id'] = $lead->package_departure_id;
            }
            if ($lead->preferred_date) {
                $data['start_at'] = $lead->preferred_date->copy()->setTime(10, 0)->toDateTimeString();
                $data['end_at'] = ($lead->end_date ?? $lead->preferred_date)->copy()->setTime(10, 0)
                    ->addDays($lead->end_date ? 0 : 1)->toDateTimeString();
                if ($service !== ServiceType::Car->value) {
                    $data['end_at'] = \App\Filament\Resources\BookingResource::defaultEnd($service, $data['bookable_id'] ?? null, $data['start_at']);
                }
            }
            $data['notes'] = $lead->message;
        }

        if ($customer = Customer::find($this->customer)) {
            $data['customer_id'] = $customer->id;
        }

        if ($this->item) {
            $data['bookable_id'] = (int) $this->item;
        }

        $item = BookingResource::findItem($service, $data['bookable_id'] ?? null);
        if ($item instanceof Car) {
            $data['drive_mode'] = $item->self_drive ? DriveMode::SelfDrive->value : DriveMode::WithDriver->value;
        }
        if (($item instanceof Car || $item instanceof Yacht) && $item->deposit) {
            $data['security_deposit'] = (float) $item->deposit;
        }
        $departure = ! empty($data['package_departure_id']) ? \App\Models\PackageDeparture::with('package')->find($data['package_departure_id']) : null;
        if ($departure) {
            $data['start_at'] = $departure->startsAt()->toDateTimeString();
            $data['end_at'] = $departure->endsAt()->toDateTimeString();
        }
        if ($item instanceof \App\Models\Package && $item->meeting_point && empty($data['pickup_location'])) {
            $data['pickup_location'] = $item->meeting_point;
        }
        if ($item && (! empty($data['start_at']) || $item instanceof \App\Models\Package)) {
            [$data['rate_type'], $data['quantity'], $data['rate'], $data['base_amount']] = Booking::quote($item, $data['start_at'] ?? null, $data['end_at'] ?? null, $data['drive_mode'] ?? null, (int) ($data['guests'] ?? 0), $departure) ?? [null, null, null, 0];
        }

        foreach ($data as $key => $value) {
            if ($value !== null) {
                $this->data[$key] = $value;
            }
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 18 Nov (meeting item 5): without "change status", a new booking starts as at most Waiting for payment.
        $allowed = array_map(fn ($s) => $s->value, BookingResource::statusChoices(null));
        if (! in_array($data['status'] ?? null, $allowed, true)) {
            $data['status'] = \App\Enums\BookingStatus::PendingPayment->value;
        }

        if (blank($data['customer_id'] ?? null) && ($lead = Lead::find($data['lead_id'] ?? null))) {
            $data['customer_id'] = $lead->convertToCustomer()->id;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Booking $booking */
        $booking = $this->getRecord();
        $advance = (float) ($this->data['advance_amount'] ?? 0);
        BookingResource::logOverride($booking, $this->data);

        if ($advance > 0) {
            $booking->recordPayment($advance, $this->data['advance_method'] ?? null, null, 'Advance · '.$booking->reference);
        }
    }

    protected function getRedirectUrl(): string
    {
        // 19 Nov: a booking passed to another agent is not visible to an agent: back to the list, not a "not found" page.
        $visible = BookingResource::getEloquentQuery()->whereKey($this->getRecord()->getKey())->exists();

        return $visible
            ? $this->getResource()::getUrl('edit', ['record' => $this->getRecord()])
            : BookingResource::getUrl('index', ['activeTab' => 'all']);
    }
}
