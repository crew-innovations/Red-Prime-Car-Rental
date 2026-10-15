<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasLabel, HasColor
{
    case Individual = 'individual';
    case Corporate = 'corporate';
    case Tourist = 'tourist';
    case Resident = 'resident';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Corporate => 'Corporate',
            self::Tourist => 'Tourist',
            self::Resident => 'Resident',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Individual => 'gray',
            self::Corporate => 'primary',
            self::Tourist => 'info',
            self::Resident => 'success',
        };
    }
}
