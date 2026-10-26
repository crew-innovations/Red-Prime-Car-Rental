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

    public function payments()
    {
        return CustomerArea::allows('customer_sees_payments')
            ? CustomerArea::payments()->where('booking_id', $this->record->id)->orderByDesc('date')->get()
            : collect();
    }
}
