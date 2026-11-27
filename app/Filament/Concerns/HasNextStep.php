<?php

namespace App\Filament\Concerns;

use App\Support\Sales\NextStep;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\ActionSize;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * 6 Nov (S4 / C.2): a Lead, Quotation or Booking page shows where the sale stands (progress bar)
 * and one big "next step" button. The button is the page's existing action for that step; the
 * other buttons move into "More", so nothing is removed.
 */
trait HasNextStep
{
    /** Buttons that stay next to the big one (phone and messages, used all day). */
    protected array $keepBesideNextStep = ['whatsapp', 'call'];

    protected function nextStep(): array
    {
        return NextStep::for($this->getRecord());
    }

    /** @param  array<Action|ActionGroup>  $actions */
    protected function withNextStep(array $actions): array
    {
        $next = $this->nextStep()['next'];
        $primary = null;
        $beside = [];
        $more = [];
        $groups = [];
        foreach ($actions as $a) {
            if ($a instanceof ActionGroup) {
                $groups[] = $a;
            } elseif ($next && $a->getName() === $next && ! $primary) {
                $primary = $a->color('primary')->size(ActionSize::Large)->icon('heroicon-m-arrow-right-circle')
                    ->extraAttributes(['class' => 'rp-next-step'], merge: true);
            } elseif (in_array($a->getName(), $this->keepBesideNextStep, true)) {
                $beside[] = $a->color('gray');
            } else {
                $more[] = $a;
            }
        }

        return array_values(array_filter([
            $primary,
            ...$beside,
            $more ? ActionGroup::make($more)->label('More')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray') : null,
            ...$groups,
        ]));
    }

    /** The progress bar, above the page's own line of details. */
    protected function progressBar(?string $subheading): Htmlable
    {
        return new HtmlString(view('filament.components.progress', ['step' => $this->nextStep(), 'sub' => $subheading])->render());
    }
}
