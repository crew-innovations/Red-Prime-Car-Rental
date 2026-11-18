<?php

namespace App\Support\Documents;

use Illuminate\Support\HtmlString;

/**
 * Labels for customer PDFs in English, Arabic or both (4 Nov, 12.2), from
 * lang/en/pdf.php and lang/ar/pdf.php. Arabic is shaped for dompdf and printed
 * with DejaVu Sans (Poppins has no Arabic letters).
 */
class PdfText
{
    public const LANGUAGES = ['en' => 'English', 'ar' => 'Arabic', 'both' => 'English and Arabic'];

    public function __construct(public string $lang = 'en')
    {
        $this->lang = array_key_exists($lang, self::LANGUAGES) ? $lang : 'en';
    }

    /** Default language for a customer: Arabic speakers get both languages. */
    public static function forCustomer(?string $customerLanguage): string
    {
        return in_array(strtolower((string) $customerLanguage), ['ar', 'arabic', 'العربية'], true) ? 'both' : 'en';
    }

    public function rtl(): bool
    {
        return $this->lang === 'ar';
    }

    /** A label: "Total", "الإجمالي", or "Total الإجمالي" (both). */
    public function t(string $key, array $replace = []): HtmlString
    {
        $en = (string) trans('pdf.'.$key, $replace, 'en');
        $ar = (string) trans('pdf.'.$key, $replace, 'ar');

        return new HtmlString(match ($this->lang) {
            'ar' => self::ar($ar),
            'both' => e($en).' <span class="ar-sub">'.self::ar($ar).'</span>',
            default => e($en),
        });
    }

    /** Plain text in the document's main language (for places where two languages don't fit). */
    public function plain(string $key, array $replace = []): string
    {
        return (string) trans('pdf.'.$key, $replace, $this->lang === 'ar' ? 'ar' : 'en');
    }

    /** Any text (names, notes): Arabic parts are shaped and set in the Arabic font. */
    public static function x(?string $text): HtmlString
    {
        $text = (string) $text;

        return new HtmlString(ArabicText::has($text) ? self::ar($text) : e($text));
    }

    /** A paragraph that may contain Arabic, kept in reading order line by line. */
    public static function paragraph(?string $text, int $max = 95): HtmlString
    {
        $text = (string) $text;
        if (! ArabicText::has($text)) {
            return new HtmlString(nl2br(e($text)));
        }

        return new HtmlString(collect(ArabicText::lines($text, $max))
            ->map(fn ($l) => ArabicText::has($l) ? '<div class="ar" style="text-align:right">'.e($l).'</div>' : '<div>'.e($l).'</div>')->implode(''));
    }

    protected static function ar(string $text): string
    {
        return '<span class="ar">'.e(ArabicText::shape($text)).'</span>';
    }
}
