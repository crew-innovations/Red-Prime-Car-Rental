<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * 6 Nov (S4 / C.2): page help ("?") and empty-list text, all from lang/en/help.php.
 * 20 Nov (item 12): the "?" now opens the full guide of the page (App\Support\HelpGuides) in a
 * panel on the side; the short lines stay at the top as "In short". Help centre: /crm/help.
 */
class Help
{
    public static function key(): string
    {
        $name = (string) request()->route()?->getName();

        return Str::startsWith($name, 'filament.account.')
            ? Str::after($name, 'filament.account.')
            : Str::after($name, 'filament.admin.');
    }

    public static function inPortal(): bool
    {
        return Str::startsWith((string) request()->route()?->getName(), 'filament.account.');
    }

    /** @return list<string> */
    public static function lines(?string $key = null): array
    {
        $all = trans('help.pages');

        return is_array($all) ? ($all[$key ?? self::key()] ?? []) : [];
    }

    /** The guide shown on a page (with the "how to" that opens first), or null. */
    public static function guide(?string $key = null, ?bool $portal = null): ?array
    {
        $key ??= self::key();
        $portal ??= self::inPortal();

        if ($portal) {
            $guide = HelpGuides::portal();
            $focus = HelpGuides::PORTAL_FOCUS[$key] ?? null;
        } else {
            $id = HelpGuides::forRoute($key);
            $guide = $id ? (HelpGuides::all()[$id] ?? null) : null;
            if ($guide) {
                $guide['id'] = $id;
            }
            $focus = HelpGuides::FOCUS[$key] ?? null;
        }
        if (! $guide) {
            return null;
        }

        // The "how to" for this page first, open.
        $steps = $guide['steps'] ?? [];
        if ($focus && isset($steps[$focus])) {
            $steps = [$focus => $steps[$focus]] + $steps;
        }
        $guide['steps'] = $steps;
        $guide['focus'] = $focus && isset($steps[$focus]) ? $focus : null;
        $guide['short'] = $portal ? [] : self::lines($key);

        return $guide;
    }

    public static function button(): HtmlString
    {
        if (! auth()->check()) {
            return new HtmlString('');
        }
        $guide = self::guide();
        if (! $guide) {
            return new HtmlString('');
        }

        return new HtmlString(view('filament.help.button', [
            'guide' => $guide,
            'centre' => self::inPortal() ? null : \App\Filament\Pages\HelpCentre::getUrl(),
        ])->render());
    }

    public static function empty(?string $slug): string
    {
        $all = trans('help.empty');

        return is_array($all) ? ($all[$slug] ?? $all['default']) : '';
    }
}
