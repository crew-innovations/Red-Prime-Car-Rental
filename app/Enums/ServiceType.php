<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ServiceType: string implements HasLabel, HasColor
{
    case CarRental = 'car';
    case YachtRental = 'yacht';
    case YachtTrip = 'trip';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::CarRental => 'Car Rental',
            self::YachtRental => 'Yacht Rental',
            self::YachtTrip => 'Yacht Trip',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CarRental => 'primary',
            self::YachtRental => 'info',
            self::YachtTrip => 'success',
            self::Other => 'gray',
        };
    }
}
