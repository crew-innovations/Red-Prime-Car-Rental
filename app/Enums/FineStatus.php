<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Traffic fine statuses (4 Nov brief, 2.4). */
enum FineStatus: string implements HasLabel, HasColor
{
    case New = 'new';                     // not matched yet: needs review
    case Matched = 'matched';             // the booking / customer is known
    case Charged = 'charged';             // added to the customer's booking
    case Collected = 'collected';         // the customer paid it
    case PaidToAuthority = 'paid';        // we paid the authority
    case Disputed = 'disputed';
    case Waived = 'waived';               // not charged (goodwill); cost to the company
    case CompanyCost = 'company';         // our own use / no customer
    case RechargedSupplier = 'supplier';  // a hired-in car: the supplier pays

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Needs review',
            self::Matched => 'Matched',
            self::Charged => 'Charged to customer',
            self::Collected => 'Collected',
            self::PaidToAuthority => 'Paid to authority',
            self::Disputed => 'Disputed',
            self::Waived => 'Waived',
            self::CompanyCost => 'Company cost',
            self::RechargedSupplier => 'Recharged to supplier',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'danger',
            self::Matched => 'warning',
            self::Charged => 'info',
            self::Collected, self::PaidToAuthority => 'success',
            self::Disputed => 'warning',
            default => 'gray',
        };
    }
}
