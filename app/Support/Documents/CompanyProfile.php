<?php

namespace App\Support\Documents;

use App\Models\Setting;
use App\Support\Tax;
use Illuminate\Support\Facades\Storage;

/**
 * The company details every PDF reads (upgrade brief 4 Nov, 12.1), from
 * Business settings › Company & messages › Company profile.
 * Empty fields are simply left out; missing() lists what still needs filling in.
 */
class CompanyProfile
{
    /** Fields a customer document should carry. */
    public const REQUIRED = [
        'company_legal_name' => 'legal name',
        'company_trade_licence' => 'trade licence number',
        'company_address' => 'address',
        'company_phone' => 'phone',
        'company_email' => 'email',
    ];

    public static function values(): array
    {
        $s = Setting::values();

        return [
            'trade_name' => ($s['company_name'] ?? null) ?: 'RED PRIME RENTALS',
            'legal_name' => ($s['company_legal_name'] ?? null) ?: (($s['company_name'] ?? null) ?: 'RED PRIME RENTALS'),
            'licence' => $s['company_trade_licence'] ?? null,
            'trn' => Tax::enabled() ? ($s['company_trn'] ?? null) : null,
            'address' => $s['company_address'] ?? null,
            'phone' => $s['company_phone'] ?? null,
            'whatsapp' => $s['company_whatsapp'] ?? null,
            'email' => $s['company_email'] ?? null,
            'website' => ($s['company_website'] ?? null) ?: null,
        ];
    }

    /** @return list<string> labels of empty fields (VAT on: the TRN too). */
    public static function missing(): array
    {
        $s = Setting::values();
        $out = [];
        foreach (self::REQUIRED as $key => $label) {
            if (blank($s[$key] ?? null)) {
                $out[] = $label;
            }
        }
        if (Tax::enabled() && blank($s['company_trn'] ?? null)) {
            $out[] = 'TRN (VAT is on)';
        }
        if (blank($s['bank_iban'] ?? null) && blank($s['bank_details'] ?? null)) {
            $out[] = 'bank details';
        }

        return $out;
    }

    /** Bank details as label => value (structured fields), plus the free-text note. */
    public static function bank(): array
    {
        $s = Setting::values();

        return [
            'rows' => array_filter([
                'Bank' => $s['bank_name'] ?? null,
                'Account name' => $s['bank_account_name'] ?? null,
                'IBAN' => $s['bank_iban'] ?? null,
                'SWIFT' => $s['bank_swift'] ?? null,
            ], fn ($v) => filled($v)),
            'note' => filled($s['bank_details'] ?? null) ? (string) $s['bank_details'] : null,
        ];
    }

    /** Logo for white paper as a data URI: the uploaded one, else the built-in logo. */
    public static function logo(): ?string
    {
        $path = Setting::read('company_logo');
        if (filled($path) && ($uri = self::dataUri('uploads', (string) $path))) {
            return $uri;
        }

        return Pdf::logo();
    }

    public static function stamp(): ?string
    {
        $path = Setting::read('company_stamp');

        return filled($path) ? self::dataUri('private', (string) $path) : null;
    }

    protected static function dataUri(string $disk, string $path): ?string
    {
        try {
            if (str_contains($path, '..') || ! Storage::disk($disk)->exists($path)) {
                return null;
            }
            $bytes = (string) Storage::disk($disk)->get($path);
            $mime = str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg';

            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        } catch (\Throwable) {
            return null;
        }
    }
}
