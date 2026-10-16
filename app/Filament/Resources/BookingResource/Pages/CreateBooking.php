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
    protected static string $resource = BookingResource::class;

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
        if (! in_array($service, [ServiceType::Car->value, ServiceType::Yacht->value, ServiceType::Trip->value], true)) {
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
            if ($lead->preferred_date) {
                $data['start_at'] = $lead->preferred_date->copy()->setTime(10, 0)->toDateTimeString();
                $data['end_at'] = ($lead->end_date ?? $lead->preferred_date)->copy()->setTime(10, 0)
                    ->addDays($lead->end_date ? 0 : 1)->toDateTimeString();
                if ($service !== ServiceType::Car->value) {
                    $data['end_at'] = Carbon::parse($data['start_at'])->addHours(\App\Support\Rules::defaultHours($service))->toDateTimeString();
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
        if ($item && ! empty($data['start_at'])) {
            [$data['rate_type'], $data['quantity'], $data['rate'], $data['base_amount']] = Booking::quote($item, $data['start_at'], $data['end_at'], $data['drive_mode'] ?? null, (int) ($data['guests'] ?? 0)) ?? [null, null, null, 0];
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
