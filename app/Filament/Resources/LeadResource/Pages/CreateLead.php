<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Filament\Resources\LeadResource;
use App\Models\Customer;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Url;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    /** Set when "New enquiry" is clicked on a customer profile. */
    #[Url]
    public ?string $customer = null;

    protected function afterFill(): void
    {
        if ($customer = Customer::find($this->customer)) {
            $this->data['name'] = $customer->name;
            $this->data['phone'] = $customer->phone;
            $this->data['whatsapp'] = $customer->whatsapp;
            $this->data['email'] = $customer->email;
            $this->data['lead_source_id'] = $this->data['lead_source_id'] ?? null;
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($customer = Customer::find($this->customer)) {
            $data['customer_id'] = $customer->id;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
