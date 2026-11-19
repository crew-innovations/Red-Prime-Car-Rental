<?php

namespace App\Support\Ocr;

use Illuminate\Support\Carbon;

/**
 * 19 Nov (Bilal: "auto get details from documents… for now use something within this system").
 * The text of each document is read on the staff member's own computer (Tesseract in the browser,
 * no outside service); this class finds the details in that text. The passport / ID machine
 * zone (MRZ) is trusted first because it has check digits; printed labels come next.
 * Nothing here is sure: the form only fills empty fields and staff check them.
 */
class TextParser
{
    /** ISO 3166 alpha-3 (as in the MRZ) → the nationality names used in the customer form. */
    public const COUNTRIES = [
        'ARE' => 'United Arab Emirates', 'SAU' => 'Saudi Arabia', 'KWT' => 'Kuwait', 'QAT' => 'Qatar', 'BHR' => 'Bahrain', 'OMN' => 'Oman',
        'IND' => 'India', 'PAK' => 'Pakistan', 'BGD' => 'Bangladesh', 'LKA' => 'Sri Lanka', 'NPL' => 'Nepal', 'PHL' => 'Philippines',
        'EGY' => 'Egypt', 'JOR' => 'Jordan', 'LBN' => 'Lebanon', 'SYR' => 'Syria', 'IRQ' => 'Iraq', 'IRN' => 'Iran', 'YEM' => 'Yemen',
        'GBR' => 'United Kingdom', 'USA' => 'United States', 'CAN' => 'Canada', 'AUS' => 'Australia', 'NZL' => 'New Zealand', 'IRL' => 'Ireland',
        'DEU' => 'Germany', 'D' => 'Germany', 'FRA' => 'France', 'ITA' => 'Italy', 'ESP' => 'Spain', 'PRT' => 'Portugal', 'NLD' => 'Netherlands',
        'BEL' => 'Belgium', 'CHE' => 'Switzerland', 'AUT' => 'Austria', 'SWE' => 'Sweden', 'NOR' => 'Norway', 'DNK' => 'Denmark', 'FIN' => 'Finland',
        'POL' => 'Poland', 'RUS' => 'Russia', 'UKR' => 'Ukraine', 'KAZ' => 'Kazakhstan', 'UZB' => 'Uzbekistan', 'TUR' => 'Turkey', 'GRC' => 'Greece',
        'CHN' => 'China', 'JPN' => 'Japan', 'KOR' => 'South Korea', 'IDN' => 'Indonesia', 'MYS' => 'Malaysia', 'SGP' => 'Singapore', 'THA' => 'Thailand',
        'NGA' => 'Nigeria', 'KEN' => 'Kenya', 'ZAF' => 'South Africa', 'ETH' => 'Ethiopia', 'MAR' => 'Morocco', 'TUN' => 'Tunisia', 'DZA' => 'Algeria',
        'SDN' => 'Sudan', 'AFG' => 'Afghanistan', 'BRA' => 'Brazil', 'MEX' => 'Mexico', 'ARG' => 'Argentina', 'AZE' => 'Azerbaijan', 'ARM' => 'Armenia', 'GEO' => 'Georgia',
    ];

    /**
     * @param  list<array{type?: ?string, text?: ?string}>  $docs  upload key (emirates_id, passport, driving_licence, trade_licence…) and its text
     * @return array<string, mixed>  only what was found (same keys as DocumentReader::CUSTOMER_FIELDS)
     */
    public static function customer(array $docs): array
    {
        $out = [];
        $put = function (string $k, $v) use (&$out) {
            if ($v !== null && $v !== '' && ! array_key_exists($k, $out)) {
                $out[$k] = $v;
            }
        };

        // 1. Machine zones first (check digits).
        foreach ($docs as $d) {
            $m = Mrz::parse(self::mrzText((string) ($d['text'] ?? '')));
            if (! $m || ! $m['valid']) {
                continue;
            }
            $name = trim(($m['given_names'] ?? '').' '.($m['surname'] ?? ''));
            $put('name', $name ?: null);
            $put('date_of_birth', $m['date_of_birth']);
            $put('nationality', self::COUNTRIES[$m['nationality'] ?? ''] ?? null);
            if ($m['type'] === 'passport') {
                $put('passport_number', $m['number']);
                $put('passport_expires_on', $m['expires_on']);
            } elseif ($m['number'] && str_starts_with($m['number'], '784')) {
                $put('id_number', DocumentReader::emiratesId($m['number']));
                $put('id_expires_on', $m['expires_on']);
            }
        }

        // 2. Printed labels, document by document.
        foreach ($docs as $d) {
            $type = (string) ($d['type'] ?? '');
            $text = self::tidy((string) ($d['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $dates = self::labelledDates($text);

            if (preg_match('/\b784[\s\-.]?\d{4}[\s\-.]?\d{7}[\s\-.]?\d\b/', $text, $m)) {
                $put('id_number', DocumentReader::emiratesId($m[0]));
            }
            if (preg_match('/^\s*Name\s*[:.]?\s*([A-Za-z][A-Za-z .\'\-]{2,60})$/mi', $text, $m)) {
                $put('name', self::title($m[1]));
            }
            if (preg_match('/Nationality\s*[:.]?\s*([A-Za-z][A-Za-z ]{2,40})/i', $text, $m)) {
                $put('nationality', self::nationality($m[1]));
            }
            $put('date_of_birth', $dates['birth'] ?? null);

            switch ($type) {
                case 'emirates_id':
                    $put('id_expires_on', $dates['expiry'] ?? self::latestFuture($text));
                    break;
                case 'passport':
                    if (preg_match('/Passport\s*(?:No|Number)\.?\s*[:.]?\s*([A-Z]{0,2}\d{6,9})\b/i', $text, $m)) {
                        $put('passport_number', strtoupper($m[1]));
                    }
                    $put('passport_expires_on', $dates['expiry'] ?? self::latestFuture($text));
                    break;
                case 'driving_licence':
                case 'international_licence':
                    if (preg_match('/(?:Licen[cs]e|Permit)\s*(?:No|Number)\.?\s*[:.]?\s*([A-Z0-9][A-Z0-9\-\/]{3,19})/i', $text, $m)) {
                        $put('licence_number', strtoupper($m[1]));
                    } elseif (preg_match('/(?<![\d\/\-.])(\d{5,10})(?![\d\/\-.])/', $text, $m) && ! str_starts_with($m[1], '784')) {
                        $put('licence_number', $m[1]);
                    }
                    $put('licence_issued_on', $dates['issue'] ?? null);
                    $put('licence_expires_on', $dates['expiry'] ?? self::latestFuture($text));
                    if (preg_match('/United\s+Arab\s+Emirates|\bU\.?A\.?E\.?\b|الإمارات/u', $text)) {
                        $put('licence_country', 'United Arab Emirates');
                    }
                    break;
                case 'trade_licence':
                    if (preg_match('/Licen[cs]e\s*(?:No|Number)\.?\s*[:.]?\s*([A-Z0-9][A-Z0-9\-\/]{3,19})/i', $text, $m)) {
                        $put('trade_licence_number', strtoupper($m[1]));
                    }
                    if (preg_match('/(?:Trade|Company)\s*Name\s*[:.]?\s*(.{3,80})$/mi', $text, $m)) {
                        $put('company_name', trim($m[1]));
                    }
                    $put('trade_licence_expires_on', $dates['expiry'] ?? self::latestFuture($text));
                    break;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $texts
     * @return array<string, mixed>  vendor, date, amount, vat_amount, trn, invoice_number (only what was found)
     */
    public static function receipt(array $texts): array
    {
        $text = self::tidy(implode("\n", $texts));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
        $out = [];

        if (preg_match('/TRN\s*(?:No|Number)?\.?\s*[:#]?\s*((?:\d\s?){15})/i', $text, $m) || preg_match('/\b(100\d{12})\b/', $text, $m)) {
            $out['trn'] = preg_replace('/\D/', '', $m[1]);
        }
        if (preg_match('/(?:Invoice|Inv|Receipt|Bill|Ref)\s*(?:No|#|Number)\.?\s*[:#]?\s*([A-Z0-9][A-Z0-9\-\/]{2,19})/i', $text, $m)) {
            $out['invoice_number'] = strtoupper($m[1]);
        }
        if ($date = self::dates($text)[0] ?? null) {
            $out['date'] = $date;
        }

        // Total: the best labelled line, else the biggest money amount.
        $best = null;
        foreach ($lines as $l) {
            if (! preg_match('/total|amount\s*due|net\s*amount|grand|balance\s*due|الإجمالي|المجموع/iu', $l) || preg_match('/sub\s*-?\s*total|total\s*(?:qty|items?)/i', $l)) {
                continue;
            }
            $n = self::lastMoney($l);
            if ($n !== null) {
                $rank = preg_match('/grand|incl|amount\s*due|net\s*amount|payable/i', $l) ? 2 : 1;
                if (! $best || $rank > $best[0] || ($rank === $best[0] && $n >= $best[1])) {
                    $best = [$rank, $n];
                }
            }
        }
        if ($best) {
            $out['amount'] = $best[1];
        } else {
            preg_match_all('/\d{1,3}(?:,\d{3})*\.\d{2}\b|\b\d+\.\d{2}\b/', $text, $all);
            $nums = array_map(fn ($s) => (float) str_replace(',', '', $s), $all[0] ?? []);
            if ($nums) {
                $out['amount'] = max($nums);
            }
        }
        foreach ($lines as $l) {
            if (preg_match('/\bVAT\b|ضريبة/iu', $l) && ! preg_match('/TRN|registration/i', $l)) {
                $l2 = preg_replace('/\d+(?:\.\d+)?\s*%/', '', $l);
                if (($n = self::lastMoney($l2)) !== null && (! isset($out['amount']) || $n < $out['amount'])) {
                    $out['vat_amount'] = $n;
                    break;
                }
            }
        }
        foreach (array_slice($lines, 0, 6) as $l) {
            if (preg_match('/[A-Za-z]{3,}/', $l) && ! preg_match('/tax\s*invoice|receipt|invoice|^date|TRN|tel|phone|www\.|@|^\W*$/i', $l)) {
                $out['vendor'] = mb_substr(trim($l, " \t-:*"), 0, 120);
                break;
            }
        }

        return $out;
    }

    /* ---------- helpers ---------- */

    /** Common OCR slips in the machine zone: spaces, « and lowercase. */
    protected static function mrzText(string $text): string
    {
        return collect(preg_split('/\R/', $text))
            ->map(fn ($l) => str_replace(['«', '‹', ' '], ['<', '<', ''], strtoupper((string) $l)))
            ->implode("\n");
    }

    protected static function tidy(string $text): string
    {
        return trim(preg_replace("/[ \t]+/", ' ', str_replace(["\r\n", "\r"], "\n", $text)));
    }

    protected static function title(string $s): string
    {
        return ucwords(strtolower(trim(preg_replace('/\s+/', ' ', $s))));
    }

    protected static function nationality(string $s): ?string
    {
        $s = self::title($s);
        $names = array_unique(array_values(self::COUNTRIES));
        foreach ($names as $n) {
            if (strcasecmp($n, $s) === 0 || str_starts_with(strtolower($s), strtolower($n))) {
                return $n;
            }
        }
        $demonyms = ['Indian' => 'India', 'Pakistani' => 'Pakistan', 'Emirati' => 'United Arab Emirates', 'British' => 'United Kingdom', 'American' => 'United States',
            'Egyptian' => 'Egypt', 'Filipino' => 'Philippines', 'Russian' => 'Russia', 'German' => 'Germany', 'French' => 'France', 'Jordanian' => 'Jordan',
            'Lebanese' => 'Lebanon', 'Syrian' => 'Syria', 'Saudi' => 'Saudi Arabia', 'Bangladeshi' => 'Bangladesh', 'Sri Lankan' => 'Sri Lanka', 'Nepalese' => 'Nepal'];
        foreach ($demonyms as $d => $n) {
            if (str_starts_with($s, $d)) {
                return $n;
            }
        }

        return null;
    }

    /** All dates in reading order, as Y-m-d (UAE documents are day / month / year). */
    public static function dates(string $text): array
    {
        preg_match_all('/\b(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\b|\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b|\b(\d{1,2})[\s\-]([A-Za-z]{3})[A-Za-z]*[\s\-,]+(\d{4})\b/', $text, $all, PREG_SET_ORDER);
        $out = [];
        foreach ($all as $m) {
            [$y, $mo, $d] = match (true) {
                ($m[1] ?? '') !== '' => [(int) $m[1], (int) $m[2], (int) $m[3]],
                ($m[4] ?? '') !== '' => [(int) $m[6], (int) $m[5], (int) $m[4]],
                default => [(int) $m[9], (int) (array_search(strtolower($m[8]), ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true) + 1), (int) $m[7]],
            };
            if ($mo >= 1 && $mo <= 12 && checkdate($mo, $d, $y) && $y > 1900 && $y < 2100) {
                $out[] = Carbon::create($y, $mo, $d)->toDateString();
            }
        }

        return $out;
    }

    /** Dates next to "birth", "issue" or "expiry" labels (same line, or the label on the line above). */
    protected static function labelledDates(string $text): array
    {
        $lines = explode("\n", $text);
        $found = [];
        $labels = [
            'birth' => '/birth|D\.?O\.?B|الميلاد/iu',
            'issue' => '/issu|الإصدار/iu',
            'expiry' => '/expir|valid\s*(?:until|till|to)|exp\.?\s*date|الانتهاء/iu',
        ];
        foreach ($lines as $i => $line) {
            foreach ($labels as $key => $re) {
                if (isset($found[$key]) || ! preg_match($re, $line)) {
                    continue;
                }
                $d = self::dates($line) ?: self::dates($lines[$i + 1] ?? '');
                if ($d) {
                    $found[$key] = $d[0];
                }
            }
        }

        return $found;
    }

    protected static function latestFuture(string $text): ?string
    {
        $future = array_filter(self::dates($text), fn ($d) => $d > today()->toDateString());

        return $future ? max($future) : null;
    }

    protected static function lastMoney(string $line): ?float
    {
        if (! preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?/', $line, $m) || ! $m[0]) {
            return null;
        }
        $n = (float) str_replace(',', '', end($m[0]));

        return $n > 0 ? round($n, 2) : null;
    }
}
