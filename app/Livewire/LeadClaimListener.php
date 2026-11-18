<?php

namespace App\Livewire;

use App\Models\Lead;
use App\Support\Leads\LeadBroadcast;
use Filament\Notifications\Notification;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * 5 Nov (S3): the buttons in the bell that do the job right there. Sits invisibly on every CRM
 * page: "Accept" on a new lead (the same safe claim as everywhere else), and for Admin "Approve"
 * on a price or a cancellation request.
 */
class LeadClaimListener extends Component
{
    #[On('rp-claim-lead')]
    public function claim(int $lead): void
    {
        $user = auth()->user();
        $model = Lead::query()->find($lead);
        if (! $user || ! $model) {
            Notification::make()->title('This lead is no longer available')->warning()->send();

            return;
        }
        [$won, $owner] = LeadBroadcast::claim($model, $user, 'bell', request()->ip());
        if ($won) {
            Notification::make()->title('The lead is yours')->body('Call or message them now.')->success()->send();
            $this->redirect(\App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $model]));

            return;
        }
        Notification::make()->title($owner ? 'Taken by '.$owner->name : 'Could not accept this lead')->danger()->send();
        $this->dispatch('databaseNotificationsSent'); // refresh the bell
    }

    /** 5 Nov (S3, B.8): "Approve" on a price waiting for approval, from the bell. Admin only. */
    #[On('rp-approve-price')]
    public function approvePrice(string $type, int $id): void
    {
        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type);
        $record = in_array($class, [\App\Models\Quotation::class, \App\Models\Booking::class], true) ? $class::find($id) : null;
        if (! $record || ! auth()->user()?->can('approve-prices')) {
            Notification::make()->title('Not allowed')->danger()->send();

            return;
        }
        try {
            $record->approvePrice();
            Notification::make()->title('Price approved: '.($record->number ?? $record->reference))->success()->send();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
        }
    }

    /** 5 Nov (S3, B.8): "Approve" on an agent's cancellation request, from the bell. Admin only. */
    #[On('rp-approve-cancel')]
    public function approveCancel(int $booking): void
    {
        $b = \App\Models\Booking::find($booking);
        if (! $b || ! \App\Support\Access::isAdmin()) {
            Notification::make()->title('Not allowed')->danger()->send();

            return;
        }
        if (! $b->cancel_requested_at || ! in_array($b->status, \App\Filament\Resources\BookingResource\BookingActions::CANCELLABLE, true)) {
            Notification::make()->title('Nothing to approve any more')->warning()->send();

            return;
        }
        $by = $b->cancel_requested_by ? \App\Models\User::find($b->cancel_requested_by) : null;
        $reason = (string) $b->cancel_request_reason;
        $b->update(['status' => \App\Enums\BookingStatus::Cancelled, 'cancel_reason' => $reason,
            'cancel_requested_at' => null, 'cancel_requested_by' => null, 'cancel_request_reason' => null]);
        \App\Support\Drivers\Notify::person($by, 'Cancellation approved: '.$b->reference, $reason, \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $b]), 'success', 'cancel_requested', 'cancel-decided:'.$b->id.':'.now()->timestamp);
        Notification::make()->title('Booking '.$b->reference.' cancelled')->body('Record any refund in Accounts.')->success()->send();
    }

    /** 5 Nov (S5): "Confirm" on a driver's fill-up, from the bell. Accounts / Admin. */
    #[On('rp-confirm-fuel')]
    public function confirmFuel(int $entry): void
    {
        $e = \App\Models\FuelEntry::find($entry);
        if (! $e || ! auth()->user()?->can('manage-finance')) {
            Notification::make()->title('Not allowed')->danger()->send();

            return;
        }
        if ($e->status !== 'waiting') {
            Notification::make()->title('Already '.strtolower(\App\Models\FuelEntry::STATUSES[$e->status] ?? $e->status))->warning()->send();

            return;
        }
        \App\Support\Fuel\Fuel::confirm($e, auth()->user());
        Notification::make()->title('Fill-up confirmed: '.$e->car?->name)->body('The fuel expense was added.')->success()->send();
    }

    public function render(): string
    {
        return '<div hidden></div>';
    }
}
