<?php

namespace App\Support\Documents;

/**
 * Arabic text for dompdf (4 Nov, 12.2). dompdf neither joins Arabic letters nor
 * lays out right-to-left text, so before printing, the letters are swapped for
 * their joined forms (Unicode presentation forms, which DejaVu Sans has) and
 * the text is put in visual order (Arabic runs reversed, numbers and Latin
 * words kept left-to-right). Text without Arabic is returned unchanged.
 */
class ArabicText
{
    /** letter => [isolated, final, initial, medial]; two forms = joins only to the letter before. */
    protected const FORMS = [
        0x0621 => [0xFE80],
        0x0622 => [0xFE81, 0xFE82], 0x0623 => [0xFE83, 0xFE84], 0x0624 => [0xFE85, 0xFE86], 0x0625 => [0xFE87, 0xFE88],
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], 0x0627 => [0xFE8D, 0xFE8E], 0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92],
        0x0629 => [0xFE93, 0xFE94], 0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98], 0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C],
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0], 0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4], 0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8],
        0x062F => [0xFEA9, 0xFEAA], 0x0630 => [0xFEAB, 0xFEAC], 0x0631 => [0xFEAD, 0xFEAE], 0x0632 => [0xFEAF, 0xFEB0],
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4], 0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8], 0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC],
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0], 0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4], 0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8],
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC], 0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0],
        0x0640 => [0x0640, 0x0640, 0x0640, 0x0640],
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4], 0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8], 0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC],
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0], 0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4], 0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8],
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC], 0x0648 => [0xFEED, 0xFEEE], 0x0649 => [0xFEEF, 0xFEF0],
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
    ];

    /** alef after lam => [isolated, final] ligature */
    protected const LAM_ALEF = [0x0622 => [0xFEF5, 0xFEF6], 0x0623 => [0xFEF7, 0xFEF8], 0x0625 => [0xFEF9, 0xFEFA], 0x0627 => [0xFEFB, 0xFEFC]];

    protected const MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{', '<' => '>', '>' => '<', '«' => '»', '»' => '«'];

    public static function has(?string $text): bool
    {
        return $text !== null && preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text) === 1;
    }

    /** One line of text, joined and in visual order. */
    public static function shape(?string $text): string
    {
        $text = (string) $text;
        if (! self::has($text)) {
            return $text;
        }

        return self::visual(self::join(preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text)));
    }

    /**
     * A paragraph: wrapped into lines of about $max characters in reading order first,
     * then each line shaped, so lines read top to bottom. Returns the lines.
     *
     * @return list<string>
     */
    public static function lines(?string $text, int $max = 80): array
    {
        $out = [];
        foreach (preg_split('/\R/u', (string) $text) as $para) {
            if (! self::has($para)) {
                $out[] = $para;

                continue;
            }
            $line = '';
            foreach (preg_split('/\s+/u', trim($para)) as $word) {
                if ($line !== '' && mb_strlen($line.' '.$word) > $max) {
                    $out[] = self::shape($line);
                    $line = $word;
                } else {
                    $line = $line === '' ? $word : $line.' '.$word;
                }
            }
            $out[] = self::shape($line);
        }

        return $out;
    }

    protected static function join(string $text): string
    {
        $cps = array_map(fn ($c) => mb_ord($c), mb_str_split($text));
        $n = count($cps);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $c = $cps[$i];
            if (! isset(self::FORMS[$c])) {
                $out[] = $c;

                continue;
            }
            $prev = $i > 0 ? $cps[$i - 1] : null;
            $joinsPrev = $prev !== null && isset(self::FORMS[$prev]) && count(self::FORMS[$prev]) === 4;
            $next = $i + 1 < $n ? $cps[$i + 1] : null;

            if ($c === 0x0644 && $next !== null && isset(self::LAM_ALEF[$next])) {
                $out[] = self::LAM_ALEF[$next][$joinsPrev ? 1 : 0];
                $i++;

                continue;
            }

            $forms = self::FORMS[$c];
            $joinsNext = count($forms) === 4 && $next !== null && isset(self::FORMS[$next]);
            $out[] = match (true) {
                $joinsPrev && $joinsNext => $forms[3],
                $joinsPrev => $forms[1],
                $joinsNext => $forms[2],
                default => $forms[0],
            };
        }

        return implode('', array_map(fn ($c) => mb_chr($c), $out));
    }

    /** Right-to-left paragraph in visual order: runs reversed, Arabic reversed inside, numbers / Latin kept. */
    protected static function visual(string $text): string
    {
        $chars = mb_str_split($text);
        $type = [];
        foreach ($chars as $i => $ch) {
            $type[$i] = match (true) {
                preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $ch) === 1 => 'R',
                preg_match('/[\p{L}\p{N}]/u', $ch) === 1 => 'L',
                default => 'N',
            };
        }
        // Neutrals between two left-to-right characters stay with them ("AED 1,200.00"); the rest follow the paragraph (RTL).
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            if ($type[$i] !== 'N') {
                continue;
            }
            $before = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($type[$j] !== 'N') {
                    $before = $type[$j];
                    break;
                }
            }
            $after = null;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($type[$j] !== 'N') {
                    $after = $type[$j];
                    break;
                }
            }
            $type[$i] = $before === 'L' && $after === 'L' ? 'L' : 'R';
        }

        $runs = [];
        foreach ($chars as $i => $ch) {
            $t = $type[$i];
            if ($runs && $runs[count($runs) - 1][0] === $t) {
                $runs[count($runs) - 1][1][] = $ch;
            } else {
                $runs[] = [$t, [$ch]];
            }
        }

        $out = '';
        foreach (array_reverse($runs) as [$t, $cs]) {
            $out .= $t === 'R'
                ? implode('', array_map(fn ($c) => self::MIRROR[$c] ?? $c, array_reverse($cs)))
                : implode('', $cs);
        }

        return $out;
    }
}
