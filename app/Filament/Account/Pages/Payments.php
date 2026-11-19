<?php

namespace App\Filament\Account\Pages;

use App\Enums\BookingStatus;
use App\Support\Access;
use App\Support\CustomerArea;
use Filament\Pages\Page;

class Payments extends Page
{
    protected static string $view = 'account.payments';

    protected static ?string $slug = 'payments';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $title = 'Payments & balance';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return Access::isCustomer() && CustomerArea::unlocked() && CustomerArea::allows('customer_sees_payments');
    }

    public function payments()
    {
        return CustomerArea::payments()->with('booking')->orderByDesc('date')->orderByDesc('id')->limit(200)->get();
    }

    public function due()
    {
        return CustomerArea::bookings()->with('bookable')->whereIn('status', BookingStatus::billable())->where('balance', '>', 0)->orderBy('start_at')->get();
    }
}
