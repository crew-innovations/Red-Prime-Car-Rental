<?php

namespace App\Filament\Account\Pages;

use App\Models\Booking;
use App\Support\Access;
use App\Support\CustomerArea;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class BookingDetail extends Page
{
    protected static string $view = 'account.booking';

    protected static ?string $slug = 'bookings/{booking}';

    protected static bool $shouldRegisterNavigation = false;

    public Booking $record;

    public static function getRelativeRouteName(): string
    {
        return 'booking';
    }

    public static function canAccess(): bool
    {
        return Access::isCustomer();
    }

    public function mount(int|string $booking): void
    {
        // Only the customer's own bookings are found; anything else is "not found".
        $this->record = CustomerArea::bookings()->with(['bookable', 'tripDate', 'packageDeparture'])->findOrFail($booking);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Booking '.$this->record->reference;
    }

    public function getBreadcrumbs(): array
    {
        return [Bookings::getUrl() => 'My bookings', $this->record->reference];
    }

    /** Online payment (28 Oct-2): open links for this booking and what can be paid now. */
    public function onlinePayment(): array
    {
        $b = $this->record->fresh();
        if (! CustomerArea::allows('customer_sees_payments') || in_array($b->status, [\App\Enums\BookingStatus::Cancelled, \App\Enums\BookingStatus::NoShow], true)
            || ! \App\Support\Payments\Payments::connected()) {
            return ['open' => collect(), 'balance' => 0];
        }
        $open = \App\Models\PaymentRequest::query()->where('booking_id', $b->id)->whereIn('status', ['open', 'processing', 'failed'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderBy('id')->get();

        return ['open' => $open, 'balance' => \App\Support\Payments\Payments::limit($b, 'balance')];
    }

    public function payOnline()
    {
        abort_unless(CustomerArea::bookings()->whereKey($this->record->id)->exists(), 404);
        try {
            $link = \App\Support\Payments\Payments::balanceLink($this->record->fresh());
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Filament\Notifications\Notification::make()->title(collect($e->errors())->flatten()->first())->warning()->send();

            return null;
        }

        return redirect()->route('pay.show', $link->token);
    }

    public function messageUs()
    {
        return redirect(Messages::getUrl(['thread' => \App\Support\Chat\Chat::threadForCustomer($this->record)->id]));
    }

    public function payments()
    {
        return CustomerArea::allows('customer_sees_payments')
            ? CustomerArea::payments()->where('booking_id', $this->record->id)->orderByDesc('date')->get()
            : collect();
    }
}
