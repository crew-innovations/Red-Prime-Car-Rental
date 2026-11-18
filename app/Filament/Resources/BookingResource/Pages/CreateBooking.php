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
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

class CreateBooking extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard; // 6 Nov (S4 / C.2): 4 steps

    protected static string $resource = BookingResource::class;

    public function getSteps(): array
    {
        return \App\Filament\Support\Wizardize::steps(BookingResource::class, $this, [
            ['Customer & car', 'heroicon-o-user', ['Customer & service'], 'Who, and what they want'],
            ['Dates', 'heroicon-o-calendar', ['Journey', 'Dates & place'], 'When and where'],
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
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
