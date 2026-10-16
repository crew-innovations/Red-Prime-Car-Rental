<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasLabel, HasColor, HasIcon
{
    case Individual = 'individual';
    case Corporate = 'corporate';
    case Agency = 'agency';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Corporate => 'Corporate',
            self::Agency => 'Agency / Concierge',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Individual => 'gray',
            self::Corporate => 'info',
            self::Agency => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Individual => 'heroicon-m-user',
            self::Corporate => 'heroicon-m-building-office-2',
            self::Agency => 'heroicon-m-briefcase',
        };
    }
}
