<?php

namespace App\Support\Mail;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Email templates with placeholders (4 Nov, 14.3), the same idea as the WhatsApp templates.
 * Unknown placeholders are left as typed so a mistake is visible in the preview.
 */
class Templates
{
    public const PLACEHOLDERS = [
        '{customer_name}', '{first_name}', '{company}', '{booking_number}', '{quote_number}', '{quote_link}',
        '{item}', '{start}', '{end}', '{total}', '{balance}', '{account_link}', '{driver_name}', '{staff_name}',
    ];

    /** Values for a record (lead, customer, booking, quotation, driver / staff user). */
    public static function values(?Model $record, ?string $lang = 'en'): array
    {
        $company = Setting::read('company_name', 'RED PRIME RENTALS');
        $fmt = fn ($d) => $d?->format('D d M Y, H:i');
        $v = ['{company}' => $company, '{account_link}' => url('/account'), '{staff_name}' => auth()->user()?->name ?? $company];

        $customer = match (true) {
            $record instanceof Customer => $record,
            $record instanceof Booking, $record instanceof Quotation => $record->customer,
            default => null,
        };
        $name = $customer?->name ?? ($record instanceof Lead ? $record->name : ($record instanceof Quotation ? $record->customerName() : ($record instanceof User ? $record->name : null)));
        $v['{customer_name}'] = (string) $name;
        $v['{first_name}'] = (string) (strtok((string) $name, ' ') ?: '');
        $v['{driver_name}'] = $record instanceof User ? $record->name : '';

        if ($record instanceof Booking) {
            $v += ['{booking_number}' => $record->reference, '{item}' => $record->title(), '{start}' => $fmt($record->start_at), '{end}' => $fmt($record->end_at),
                '{total}' => 'AED '.number_format((float) $record->amountDue(), 2), '{balance}' => 'AED '.number_format((float) $record->balance, 2)];
        }
        if ($record instanceof Quotation) {
            $v += ['{quote_number}' => $record->number, '{quote_link}' => $record->public_token ? $record->publicUrl() : '', '{item}' => $record->bookable?->name ?? $record->service_type?->getLabel(),
                '{start}' => $fmt($record->start_at), '{end}' => $fmt($record->end_at), '{total}' => 'AED '.number_format((float) $record->total, 2)];
        }

        return array_map(fn ($x) => (string) $x, $v);
    }

    /** @return array{subject: string, body: string} */
    public static function render(EmailTemplate $t, ?Model $record, string $lang = 'en'): array
    {
        $ar = $lang === 'ar' && filled($t->body_ar);
        $values = self::values($record, $lang);

        return [
            'subject' => strtr($ar ? ($t->subject_ar ?: $t->subject_en) : $t->subject_en, $values),
            'body' => strtr($ar ? $t->body_ar : $t->body_en, $values),
        ];
    }
}
