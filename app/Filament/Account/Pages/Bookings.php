<?php

namespace App\Filament\Account\Pages;

use App\Support\Access;
use App\Support\CustomerArea;
use Filament\Pages\Page;

class Bookings extends Page
{
    protected static string $view = 'account.bookings';

    protected static ?string $slug = 'bookings';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Bookings';

    protected static ?string $title = 'My bookings';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return Access::isCustomer();
    }

    public function bookings()
    {
        return CustomerArea::bookings()->with('bookable')->orderByDesc('start_at')->limit(100)->get();
    }
}
