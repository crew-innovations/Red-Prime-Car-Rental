<?php

namespace App\Filament\Account\Pages;

use App\Support\Access;
use App\Support\CustomerArea;
use Filament\Pages\Page;

class Quotations extends Page
{
    protected static string $view = 'account.quotations';

    protected static ?string $slug = 'quotations';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Quotations';

    protected static ?string $title = 'My quotations';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return Access::isCustomer() && CustomerArea::allows('customer_sees_quotations');
    }

    public function quotations()
    {
        return CustomerArea::quotations()->with('bookable')->latest()->limit(100)->get();
    }
}
