<?php

namespace App\Support\Mail;

use App\Models\Setting;
use App\Support\Documents\CompanyProfile;
use Illuminate\Support\Facades\Storage;

/** Logo, name and footer for every email (4 Nov, 13.3 / 14), from the Company profile. */
class Branding
{
    public static function name(): string
    {
        return (string) (Setting::read('company_name') ?: 'RED PRIME RENTALS');
    }

    public static function homeUrl(): string
    {
        $site = Setting::read('company_website');

        return $site ? (str_starts_with($site, 'http') ? $site : 'https://'.$site) : (string) config('app.url');
    }

    /** An absolute URL (email clients don't show embedded images reliably). */
    public static function logoUrl(): ?string
    {
        $custom = Setting::read('company_logo');
        if (filled($custom) && rescue(fn () => Storage::disk('uploads')->exists($custom), false, false)) {
            return url('uploads/'.ltrim((string) $custom, '/'));
        }

        return is_file(public_path('images/logo-mark-light.png')) ? url('images/logo-mark-light.png') : null;
    }

    public static function footerHtml(): string
    {
        $p = CompanyProfile::values();
        $lines = array_filter([
            '<strong>'.e($p['legal_name']).'</strong>'.($p['licence'] ? ' · Trade licence '.e($p['licence']) : ''),
            $p['address'] ? e(str_replace(["\r\n", "\n"], ', ', $p['address'])) : null,
            e(collect([$p['phone'], $p['whatsapp'] && $p['whatsapp'] !== $p['phone'] ? 'WhatsApp '.$p['whatsapp'] : null, $p['email'], $p['website']])->filter()->implode(' · ')),
        ]);

        return implode('<br>', $lines);
    }
}
