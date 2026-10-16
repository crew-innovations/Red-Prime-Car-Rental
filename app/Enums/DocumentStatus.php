<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum DocumentStatus: string implements HasLabel, HasColor, HasIcon
{
    case Received = 'received';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Replacement = 'replacement';

    public function getLabel(): string
    {
        return match ($this) {
            self::Received => 'To review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Replacement => 'Replacement requested',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Received => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Replacement => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Received => 'heroicon-m-eye',
            self::Approved => 'heroicon-m-check-badge',
            self::Rejected => 'heroicon-m-x-circle',
            self::Replacement => 'heroicon-m-arrow-path',
        };
    }
}
