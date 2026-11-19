<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel, HasColor, HasIcon
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Online = 'online';
    case Cheque = 'cheque';  // 19 Nov
    case Pos = 'pos';        // 19 Nov: card machine in the office / with the driver
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Card => 'Card',
            self::Online => 'Online / link',
            self::Cheque => 'Cheque',
            self::Pos => 'Card machine (POS)',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'gray',
            self::BankTransfer => 'info',
            self::Card => 'primary',
            self::Online => 'success',
            self::Cheque => 'warning',
            self::Pos => 'primary',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Cash => 'heroicon-m-banknotes',
            self::BankTransfer => 'heroicon-m-building-library',
            self::Card => 'heroicon-m-credit-card',
            self::Online => 'heroicon-m-globe-alt',
            self::Cheque => 'heroicon-m-document-text',
            self::Pos => 'heroicon-m-device-phone-mobile',
            self::Other => 'heroicon-m-ellipsis-horizontal-circle',
        };
    }
}
