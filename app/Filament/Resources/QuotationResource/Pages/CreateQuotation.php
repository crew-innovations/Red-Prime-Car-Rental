<?php

namespace App\Filament\Resources\QuotationResource\Pages;

use App\Enums\DriveMode;
use App\Enums\ServiceType;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\QuotationResource;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Yacht;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

class CreateQuotation extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard; // 6 Nov (S4 / C.2): 4 steps, ends with "Save and send"

    protected static string $resource = QuotationResource::class;

    public function getSteps(): array
    {
        return \App\Filament\Support\Wizardize::steps(QuotationResource::class, $this, [
            ['Customer & car', 'heroicon-o-user', ['Customer & service'], 'Who, and what they want'],
            ['Dates', 'heroicon-o-calendar', ['Dates'], 'When'],
            ['Price', 'heroicon-o-banknotes', ['Price'], 'Worked out for you'],
            ['Check & send', 'heroicon-o-paper-airplane', ['Shown to the customer', 'Status', 'Internal'], 'What the customer sees'],
        ]);
    }

    protected function hasSkippableSteps(): bool
    {
        return true;
    }

    protected function getSubmitFormAction(): \Filament\Actions\Action
    {
        return parent::getSubmitFormAction()->label('Save and send');
    }

    /** Prefill from a lead or a customer. */
    #[Url]
    public ?string $lead = null;

    #[Url]
    public ?string $customer = null;

    protected function afterFill(): void
    {
        $lead = Lead::find($this->lead);
        $service = $lead?->service_type?->value;
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
                $data['end_at'] = $service === ServiceType::Car->value
                    ? ($lead->end_date ?? $lead->preferred_date)->copy()->setTime(10, 0)->addDays($lead->end_date ? 0 : 1)->toDateTimeString()
                    : \App\Filament\Resources\BookingResource::defaultEnd($service, $data['bookable_id'] ?? null, $data['start_at']);
            }
        }

        if ($customer = Customer::find($this->customer)) {
            $data['customer_id'] = $customer->id;
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

    /** Creating and sending are one step: the quote opens with the send window ready. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord(), 'action' => 'send']);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Quotation saved. Check the message and send it.';
    }
}
