<?php

namespace App\Support\Ocr;

use Illuminate\Support\Carbon;

/**
 * Reads the machine-readable zone of a passport (TD3, 2 lines of 44) or an ID card such as the
 * Emirates ID back (TD1, 3 lines of 30). Pure logic, no outside service: used to check what the
 * document reader returned, because the MRZ carries check digits.
 */
class Mrz
{
    /** @return array{type: string, number: ?string, nationality: ?string, date_of_birth: ?string, expires_on: ?string, surname: ?string, given_names: ?string, valid: bool}|null */
    public static function parse(string $text): ?array
    {
        $lines = collect(preg_split('/\R/', strtoupper($text)))
            ->map(fn ($l) => preg_replace('/\s+/', '', (string) $l))
            ->filter(fn ($l) => preg_match('/^[A-Z0-9<]{28,46}$/', $l) && substr_count($l, '<') >= 2)
            ->values();

        foreach ($lines as $i => $line) {
            if (strlen($line) >= 43 && isset($lines[$i + 1]) && strlen($lines[$i + 1]) >= 43 && $line[0] === 'P') {
                $l1 = self::names44(substr(str_pad($line, 44, '<'), 0, 44));
                $l2 = self::fix(substr(str_pad($lines[$i + 1], 44, '<'), 0, 44), [[9, 9], [13, 19], [21, 27], [42, 43]], [[10, 12]]);

                return self::td3($l1, $l2);
            }
            if (strlen($line) >= 29 && isset($lines[$i + 2]) && in_array($line[0], ['I', 'A', 'C'], true)) {
                $l1 = self::fix(substr(str_pad($line, 30, '<'), 0, 30), [[14, 14]], [[2, 4]]);
                $l2 = self::fix(substr(str_pad($lines[$i + 1], 30, '<'), 0, 30), [[0, 6], [8, 14], [29, 29]], [[15, 17]]);

                return self::td1($l1, $l2, self::names44(substr(str_pad($lines[$i + 2], 30, '<'), 0, 30)));
            }
        }

        return null;
    }

    protected static function td3(string $l1, string $l2): array
    {
        [$surname, $given] = self::names(substr($l1, 5));
        $number = substr($l2, 0, 9);
        $dob = substr($l2, 13, 6);
        $exp = substr($l2, 21, 6);
        $valid = self::check($number, $l2[9]) && self::check($dob, $l2[19]) && self::check($exp, $l2[27]);

        return [
            'type' => 'passport',
            'number' => self::clean($number),
            'nationality' => self::clean(substr($l2, 10, 3)),
            'date_of_birth' => self::date($dob, true),
            'expires_on' => self::date($exp, false),
            'surname' => $surname,
            'given_names' => $given,
            'valid' => $valid,
        ];
    }

    protected static function td1(string $l1, string $l2, string $l3): array
    {
        $number = substr($l1, 5, 9);
        $optional = self::clean(substr($l1, 15, 15)); // the Emirates ID number (784-…) is kept here
        $dob = substr($l2, 0, 6);
        $exp = substr($l2, 8, 6);
        [$surname, $given] = self::names($l3);
        $valid = self::check($number, $l1[14]) && self::check($dob, $l2[6]) && self::check($exp, $l2[14]);

        return [
            'type' => 'id_card',
            'number' => $optional && preg_match('/^784\d{12}$/', $optional) ? $optional : self::clean($number),
            'nationality' => self::clean(substr($l2, 15, 3)),
            'date_of_birth' => self::date($dob, true),
            'expires_on' => self::date($exp, false),
            'surname' => $surname,
            'given_names' => $given,
            'valid' => $valid,
        ];
    }

    /**
     * 19 Nov: common text-reading slips, fixed by position (the layout is fixed): letters in number
     * fields (O→0, I→1…) and numbers in letter fields (0→O…). The check digits still decide.
     */
    protected static function fix(string $line, array $digits, array $letters): string
    {
        $toDigit = ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'G' => '6', 'B' => '8'];
        $toLetter = ['0' => 'O', '1' => 'I', '2' => 'Z', '5' => 'S', '6' => 'G', '8' => 'B'];
        foreach ([[$digits, $toDigit], [$letters, $toLetter]] as [$ranges, $map]) {
            foreach ($ranges as [$from, $to]) {
                for ($p = $from; $p <= $to && $p < strlen($line); $p++) {
                    $line[$p] = $map[$line[$p]] ?? $line[$p];
                }
            }
        }

        return $line;
    }

    /** The filler at the end of a names line is often read as "LLLL": turn it back into "<". */
    protected static function names44(string $line): string
    {
        return preg_replace_callback('/<[<L]*$/', fn ($m) => str_repeat('<', strlen($m[0])), $line);
    }

    /** ICAO 9303 check digit (weights 7, 3, 1). */
    public static function check(string $field, string $digit): bool
    {
        $sum = 0;
        foreach (str_split($field) as $i => $c) {
            $v = match (true) {
                ctype_digit($c) => (int) $c,
                $c === '<' => 0,
                default => ord($c) - 55,
            };
            $sum += $v * [7, 3, 1][$i % 3];
        }

        return ctype_digit($digit) && $sum % 10 === (int) $digit;
    }

    protected static function names(string $field): array
    {
        $parts = explode('<<', rtrim($field, '<'), 2);
        $tidy = fn ($s) => trim(ucwords(strtolower(str_replace('<', ' ', (string) $s))));

        return [$tidy($parts[0] ?? '') ?: null, $tidy($parts[1] ?? '') ?: null];
    }

    protected static function clean(string $s): ?string
    {
        $s = trim(str_replace('<', '', $s));

        return $s === '' ? null : $s;
    }

    /** YYMMDD → Y-m-d. Birth dates are in the past; expiry dates up to 30 years ahead. */
    protected static function date(string $yymmdd, bool $past): ?string
    {
        if (! preg_match('/^\d{6}$/', $yymmdd)) {
            return null;
        }
        $yy = (int) substr($yymmdd, 0, 2);
        $now = (int) date('y');
        $century = $past ? ($yy > $now ? 1900 : 2000) : ($yy > $now + 30 ? 1900 : 2000);
        $m = (int) substr($yymmdd, 2, 2);
        $d = (int) substr($yymmdd, 4, 2);
        if (! checkdate($m, $d, $century + $yy)) {
            return null;
        }

        return Carbon::create($century + $yy, $m, $d)->toDateString();
    }
}
