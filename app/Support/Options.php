<?php

namespace App\Support;

use App\Enums\DocumentType;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Every list and text the business may want to change, editable under
 * Settings › Business settings. The values below are only the starting point
 * (what the CRM shipped with); once saved, the stored values are used.
 */
class Options
{
    /** Simple suggestion lists: key => [title, help, defaults]. */
    public const LISTS = [
        'lost_reasons' => ['Lead lost reasons', 'Offered when a lead is marked lost.', [
            'Price too high', 'Car not available', 'Booked with competitor', 'No response', 'Changed plans', 'Not eligible (age / licence)',
        ]],
        'cancel_reasons' => ['Booking cancel reasons', 'Offered when a booking is cancelled or a no-show.', [
            'Customer changed plans', 'Payment not received', 'Car not available', 'Weather', 'Booked by mistake',
        ]],
        'quote_decline_reasons' => ['Quotation declined reasons', 'Offered when a customer declines a quote.', [
            'Price too high', 'Booked elsewhere', 'Changed plans', 'No response',
        ]],
        'quote_extras' => ['Quotation extras', 'Suggested extra lines on quotations (amounts are typed per quote).', [
            'Delivery & pick-up', 'Child seat', 'Additional driver', 'Full insurance', 'Decoration', 'Catering',
        ]],
        'doc_reject_reasons' => ['Document rejection reasons', 'Offered when a customer document is rejected.', [
            'Photo not clear', 'Document expired', 'Wrong document', 'Name does not match', 'Back side missing',
        ]],
        'pickup_locations' => ['Pick-up / delivery places', 'Suggested on bookings and quotations.', [
            'Office', 'Dubai International Airport (DXB)', 'Al Maktoum Airport (DWC)', 'Dubai Marina', 'Downtown Dubai', 'Palm Jumeirah',
        ]],
        'car_locations' => ['Car locations / branches', 'Suggested on the car form.', [
            'Dubai Marina', 'Downtown Dubai', 'Business Bay', 'JBR', 'Palm Jumeirah', 'Deira', 'Abu Dhabi',
        ]],
        'car_types' => ['Car types', 'Choices for a car\'s type (also used on the website filters).', [
            'Sedan', 'SUV', 'Sports', 'Supercar', 'Luxury', 'Convertible', 'Coupe', 'Hatchback', 'Van',
        ]],
        'yacht_types' => ['Yacht types', 'Choices for a yacht\'s type.', [
            'Motor Yacht', 'Superyacht', 'Sailing Yacht', 'Catamaran', 'Speedboat', 'Fishing Boat',
        ]],
        'car_brands' => ['Car brands', 'Suggested on the car form.', [
            'Audi', 'Bentley', 'BMW', 'Cadillac', 'Chevrolet', 'Ferrari', 'Ford', 'GMC', 'Honda',
            'Hyundai', 'Kia', 'Lamborghini', 'Land Rover', 'Lexus', 'Maserati', 'McLaren',
            'Mercedes-Benz', 'Mitsubishi', 'Nissan', 'Porsche', 'Rolls-Royce', 'Tesla', 'Toyota',
        ]],
        'yacht_brands' => ['Yacht builders', 'Suggested on the yacht form.', [
            'Azimut', 'Sunseeker', 'Princess', 'Ferretti', 'Majesty', 'Gulf Craft', 'Riva', 'Benetti', 'Lagoon',
        ]],
        'marinas' => ['Marinas', 'Suggested on yachts and trips.', [
            'Dubai Marina', 'Dubai Harbour', 'Bluewaters', 'Palm Jumeirah', 'Port Rashid',
            'Dubai Creek Harbour', 'Jumeirah Fishing Harbour', 'Al Seef',
        ]],
    ];

    /** WhatsApp message templates: key => [title, default]. Placeholders in {braces}. */
    public const MESSAGES = [
        'msg_greeting' => ['First message to a lead / customer', 'Hi {first_name}, this is {company}. '],
        'msg_quote' => ['Sending a quotation', 'Hi {first_name}, here is your quotation {number} from {company}: {link}'],
        'msg_upload' => ['Asking for documents', 'Hi {first_name}, please upload your documents for your {company} booking here: {link}'],
        'msg_booking' => ['Booking details', 'Hi {first_name}, your booking {reference} with {company}: {item}, {start} to {end}.{balance_line}'],
        'msg_payment_reminder' => ['Payment reminder', 'Hi {first_name}, a friendly reminder from {company} about the payment of AED {amount}. Thank you!'],
    ];

    /** Return charge lines the code relies on (labels and money category stay editable). */
    public const SYSTEM_CHARGES = ['extra_km', 'fuel', 'late'];

    public const DEFAULT_CHARGES = [
        ['key' => 'extra_km', 'label' => 'Extra km', 'category' => 'extras', 'cars_only' => true],
        ['key' => 'fuel', 'label' => 'Fuel refill', 'category' => 'extras', 'cars_only' => true],
        ['key' => 'late', 'label' => 'Late return', 'category' => 'extras', 'cars_only' => false],
        ['key' => 'salik', 'label' => 'Salik & fines', 'category' => 'fines_recovered', 'cars_only' => false],
        ['key' => 'damage', 'label' => 'Damage', 'category' => 'damage', 'cars_only' => false],
        ['key' => 'cleaning', 'label' => 'Cleaning', 'category' => 'extras', 'cars_only' => false],
        ['key' => 'other', 'label' => 'Other', 'category' => 'other_income', 'cars_only' => false],
    ];

    /** Money categories the CRM uses itself; their labels can change, they cannot be removed. */
    public const SYSTEM_CATEGORIES = [
        'income' => ['car_rental', 'yacht_charter', 'yacht_trip', 'chauffeur', 'extras', 'deposit', 'fines_recovered', 'damage', 'other_income'],
        'expense' => ['fuel', 'maintenance', 'marina', 'cleaning', 'salik_fines', 'deposit_refund', 'other_expense'],
    ];

    /* ------------------------------------------------------------------ */

    public static function list(string $key): array
    {
        $stored = Setting::read('list_'.$key);

        return is_array($stored) ? array_values(array_filter($stored, 'filled')) : (self::LISTS[$key][2] ?? []);
    }

    /** value => value for a Select, keeping a value already saved on a record even if removed from the list. */
    public static function choices(string $key, ?string $current = null): array
    {
        $items = self::list($key);
        if (filled($current) && ! in_array($current, $items, true)) {
            $items[] = $current;
        }

        return array_combine($items, $items) ?: [];
    }

    /* ---------- Customer document types ---------- */

    /** @return array<int, array{key: string, label: string, on_link: bool}> */
    public static function documentTypeRows(): array
    {
        $stored = Setting::read('document_types');
        if (is_array($stored) && $stored !== []) {
            return array_values($stored);
        }

        return collect(DocumentType::cases())->map(fn (DocumentType $t) => [
            'key' => $t->value,
            'label' => $t->getLabel(),
            'on_link' => array_key_exists($t->value, DocumentType::requestable()),
        ])->all();
    }

    /** key => label, all types. */
    public static function documentTypes(): array
    {
        return collect(self::documentTypeRows())->mapWithKeys(fn ($r) => [$r['key'] => $r['label']])->all();
    }

    /** key => label, the ones a customer can upload through a link. */
    public static function requestableDocumentTypes(): array
    {
        return collect(self::documentTypeRows())->filter(fn ($r) => ! empty($r['on_link']))->mapWithKeys(fn ($r) => [$r['key'] => $r['label']])->all();
    }

    public static function documentLabel(?string $key): string
    {
        return self::documentTypes()[$key] ?? Str::headline((string) $key);
    }

    /* ---------- Return charges ---------- */

    /** key => [label, category, cars_only] */
    public static function charges(): array
    {
        $rows = Setting::read('return_charges');
        $rows = is_array($rows) && $rows !== [] ? $rows : self::DEFAULT_CHARGES;

        $out = collect($rows)->mapWithKeys(fn ($r) => [$r['key'] => [$r['label'], $r['category'] ?? 'extras', (bool) ($r['cars_only'] ?? false)]])->all();
        foreach (self::DEFAULT_CHARGES as $r) {
            if (in_array($r['key'], self::SYSTEM_CHARGES, true) && ! isset($out[$r['key']])) {
                $out[$r['key']] = [$r['label'], $r['category'], $r['cars_only']];
            }
        }

        return $out;
    }

    public static function chargeLabel(string $key): string
    {
        return self::charges()[$key][0] ?? Str::headline($key);
    }

    /* ---------- Money categories ---------- */

    /** key => label for 'income' or 'expense'. */
    public static function categories(string $type, array $defaults): array
    {
        $rows = Setting::read('categories_'.$type);
        $map = is_array($rows) && $rows !== []
            ? collect($rows)->mapWithKeys(fn ($r) => [$r['key'] => $r['label']])->all()
            : $defaults;

        foreach (self::SYSTEM_CATEGORIES[$type] ?? [] as $key) {
            $map[$key] ??= $defaults[$key] ?? Str::headline($key);
        }

        return $map;
    }

    /* ---------- Messages ---------- */

    public static function message(string $key, array $vars = []): string
    {
        $template = (string) (Setting::read($key) ?: (self::MESSAGES[$key][1] ?? ''));
        $name = trim((string) ($vars['name'] ?? ''));
        $vars += [
            'company' => Setting::read('company_name') ?: 'RED PRIME RENTALS',
            'first_name' => $name !== '' ? Str::before($name, ' ') : '',
        ];

        $text = preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $template);

        return preg_replace('/^Hi\s+,/', 'Hi,', $text);
    }

    /** A stable key for a new list item ("Trade licence" → "trade_licence"). */
    public static function keyFor(string $label, array $taken): string
    {
        $base = Str::slug($label, '_') ?: 'item';
        $key = $base;
        $i = 2;
        while (in_array($key, $taken, true)) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }
}
