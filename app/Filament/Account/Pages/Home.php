<?php

namespace App\Filament\Account\Pages;

use App\Enums\BookingStatus;
use App\Support\Access;
use App\Support\CustomerArea;
use Filament\Pages\Dashboard;

/** The customer's start page: next booking, balance due and quick links. */
class Home extends Dashboard
{
    protected static string $view = 'account.home';

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Home';

    protected static ?string $title = 'My account';

    public static function canAccess(): bool
    {
        return Access::isCustomer();
    }

    public function getWidgets(): array
    {
        return [];
    }

    public function data(): array
    {
        $bookings = CustomerArea::bookings();

        return [
            'customer' => CustomerArea::customer(),
            'next' => (clone $bookings)->with('bookable')->whereIn('status', [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::InProgress])->orderBy('start_at')->first(),
            'balance' => (float) (clone $bookings)->whereIn('status', BookingStatus::billable())->sum('balance'),
            'quotes' => CustomerArea::allows('customer_sees_quotations') ? CustomerArea::quotations()->whereIn('status', ['sent', 'negotiation'])->count() : 0,
            'docsToFix' => CustomerArea::documents()->whereIn('status', ['rejected', 'replacement'])->count(),
            'company' => \App\Models\Setting::read('company_name', 'RED PRIME RENTALS'),
            'phone' => \App\Models\Setting::read('company_phone'),
        ];
    }
}
