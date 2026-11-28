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
            $this->data['customer_id'] = $customer->id; // 20 Nov: picked in the customer field
            $this->data['lead_source_id'] = $this->data['lead_source_id'] ?? $customer->lead_source_id;
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $pipeline = \App\Enums\Pipeline::tryFrom((string) ($data['pipeline'] ?? ''));
        $data = \App\Support\CustomStatuses::take($data, 'lead', 'stage', $pipeline?->firstStage() ?? 'new');
        $data = LeadResource::withCustomer($data);
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
