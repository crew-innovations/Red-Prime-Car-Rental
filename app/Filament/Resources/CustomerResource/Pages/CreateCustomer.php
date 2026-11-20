<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Concerns\CustomerDetails;
use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /** 20 Nov (item 9): saved like a new customer from New Booking (documents too), plus the page's own extras. */
    protected function handleRecordCreation(array $data): Model
    {
        $customer = CustomerDetails::create($data);
        $extra = collect($data)->only(CustomerResource::EXTRA_FIELDS)->all();
        if ($extra) {
            $customer->update($extra);
        }

        return $customer;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
