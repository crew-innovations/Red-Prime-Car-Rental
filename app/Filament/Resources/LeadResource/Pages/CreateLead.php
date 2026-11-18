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
        // 5 Nov (S3): only Admin gives leads to someone else; an agent's new lead is their own.
        if (! \App\Support\Access::allows('assign-leads')) {
            $data['assigned_to'] = auth()->id();
        }

        return $data;
    }

    /** 5 Nov (S3): a lead Admin added without a salesperson goes to every agent. */
    protected function afterCreate(): void
    {
        if (! $this->getRecord()->assigned_to) {
            \App\Support\Leads\Assignment::auto($this->getRecord());
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
