<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ServiceType: string implements HasLabel, HasColor, HasIcon
{
    case Car = 'car';
    case Yacht = 'yacht';
    case Trip = 'trip';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Car => 'Car Rental',
            self::Yacht => 'Yacht Rental',
            self::Trip => 'Yacht Trip',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Car => 'primary',
            self::Yacht => 'info',
            self::Trip => 'success',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Car => 'heroicon-m-truck',
            self::Yacht => 'heroicon-m-lifebuoy',
            self::Trip => 'heroicon-m-sun',
            self::Other => 'heroicon-m-question-mark-circle',
        };
    }
}
