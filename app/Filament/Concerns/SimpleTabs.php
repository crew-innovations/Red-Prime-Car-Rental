<?php

namespace App\Filament\Concerns;

use Filament\Resources\Components\Tab;

/**
 * 6 Nov (S4 / C.1): a list shows its few main tabs; the other views sit behind "More views".
 * They keep their address (?activeTab=…), so every link and notification still opens them, and
 * an extra view with something waiting (a badge) stays in sight until it is empty.
 */
trait SimpleTabs
{
    /**
     * @param  array<string, Tab>  $tabs
     * @param  list<string>  $main  always shown, in this order
     * @param  list<string>  $alerts  extra views that stay shown while something waits in them
     */
    protected function simpleTabs(array $tabs, array $main, array $alerts = []): array
    {
        $ordered = [];
        foreach ($main as $key) {
            if (isset($tabs[$key])) {
                $ordered[$key] = $tabs[$key];
            }
        }
        foreach ($tabs as $key => $tab) {
            if (isset($ordered[$key])) {
                continue;
            }
            $waiting = in_array($key, $alerts, true) && rescue(fn () => filled($tab->getBadge()), false, false);
            $ordered[$key] = $waiting ? $tab : $tab->extraAttributes(['class' => 'rp-tab-extra'], merge: true);
        }

        return $ordered;
    }
}
