<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/** 6 Nov (S4 / C.2): page help ("?") and empty-list text, all from lang/en/help.php. */
class Help
{
    public static function key(): string
    {
        return \Illuminate\Support\Str::after((string) request()->route()?->getName(), 'filament.admin.');
    }

    /** @return list<string> */
    public static function lines(?string $key = null): array
    {
        $all = trans('help.pages');

        return is_array($all) ? ($all[$key ?? self::key()] ?? []) : [];
    }

    public static function button(): HtmlString
    {
        $lines = self::lines();
        if (! $lines) {
            return new HtmlString('');
        }

        return new HtmlString('<details class="rp-help"><summary aria-label="Help for this page" title="Help">?</summary><div class="rp-help-pop" role="note"><ul>'
            .collect($lines)->map(fn ($l) => '<li>'.e($l).'</li>')->implode('').'</ul></div></details>');
    }

    public static function empty(?string $slug): string
    {
        $all = trans('help.empty');

        return is_array($all) ? ($all[$slug] ?? $all['default']) : '';
    }
}
