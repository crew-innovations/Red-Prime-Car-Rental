<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ServiceType: string implements HasLabel, HasColor, HasIcon
{
    /*
     * Yacht = private yacht charter. RED PRIME no longer offers charters (Oct 2026):
     * the value stays only so old charter bookings keep working and showing.
     */
    case Car = 'car';
    case Yacht = 'yacht';
    case Trip = 'trip';
    case Package = 'package';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Car => 'Car Rental',
            self::Yacht => 'Yacht Charter (old)',
            self::Trip => 'Yacht Trip',
            self::Package => 'Trip / Package',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Car => 'primary',
            self::Yacht => 'info',
            self::Trip => 'success',
            self::Package => 'warning',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Car => 'heroicon-m-truck',
            self::Yacht => 'heroicon-m-lifebuoy',
            self::Trip => 'heroicon-m-sun',
            self::Package => 'heroicon-m-map',
            self::Other => 'heroicon-m-question-mark-circle',
        };
    }

    /** Services staff can pick for a new lead / quotation / booking. */
    public static function offered(): array
    {
        return [self::Car, self::Trip, self::Package];
    }

    /** Options for a picker: the offered services, plus the record's own (legacy) service. */
    public static function pickerOptions(self|string|null $current = null): array
    {
        $current = $current instanceof self ? $current : self::tryFrom((string) $current);
        $list = self::offered();
        if ($current && ! in_array($current, $list, true)) {
            $list[] = $current;
        }

        return collect($list)->mapWithKeys(fn (self $s) => [$s->value => $s->getLabel()])->all();
    }

    public static function pickerIcons(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->getIcon()])->all();
    }
}
