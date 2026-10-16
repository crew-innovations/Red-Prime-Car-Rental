<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Document types from Schedule A11, plus the usual UAE rental ones. */
enum DocumentType: string implements HasLabel
{
    case Passport = 'passport';
    case EmiratesId = 'emirates_id';
    case DrivingLicence = 'driving_licence';
    case InternationalLicence = 'international_licence';
    case Visa = 'visa';
    case TradeLicence = 'trade_licence';
    case Agreement = 'agreement';
    case PaymentProof = 'payment_proof';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Passport => 'Passport',
            self::EmiratesId => 'Emirates ID',
            self::DrivingLicence => 'Driving licence',
            self::InternationalLicence => 'International driving permit',
            self::Visa => 'Visa / entry stamp',
            self::TradeLicence => 'Trade licence (company)',
            self::Agreement => 'Signed agreement',
            self::PaymentProof => 'Payment proof',
            self::Other => 'Other',
        };
    }

    /** Types a customer can be asked to upload through a link. */
    public static function requestable(): array
    {
        return collect(self::cases())
            ->reject(fn (self $t) => in_array($t, [self::Agreement, self::PaymentProof], true))
            ->mapWithKeys(fn (self $t) => [$t->value => $t->getLabel()])
            ->all();
    }
}
