<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;

/**
 * 6 Nov (S4 / C.2): turns a resource's normal form into a few wizard steps for "New …" pages,
 * without copying a single field: the sections of the form are put into steps by their heading.
 * Sections no step names go into the last step, so nothing is ever left out.
 */
class Wizardize
{
    /**
     * @param  array<int, array{0: string, 1: string, 2: list<string>, 3?: string}>  $plan  [step title, icon, section headings, description]
     * @return list<Step>
     */
    public static function steps(string $resource, $livewire, array $plan): array
    {
        $sections = [];
        self::collect($resource::form(Form::make($livewire))->getComponents(withHidden: true), $sections);

        $used = [];
        $steps = [];
        foreach ($plan as $i => $p) {
            $schema = [];
            foreach ($p[2] as $heading) {
                foreach ($sections as $k => [$h, $section]) {
                    if ($h === $heading && ! isset($used[$k])) {
                        $schema[] = $section;
                        $used[$k] = true;
                    }
                }
            }
            if ($i === array_key_last($plan)) {
                foreach ($sections as $k => [, $section]) {
                    if (! isset($used[$k])) {
                        $schema[] = $section;
                        $used[$k] = true;
                    }
                }
            }
            $steps[] = Step::make($p[0])->icon($p[1])->description($p[3] ?? null)->schema($schema);
        }

        return $steps;
    }

    /** Sections in form order (groups are opened up; a component without a heading stays whole). */
    protected static function collect(array $components, array &$out): void
    {
        foreach ($components as $c) {
            /** @var Component $c */
            if ($c instanceof Section && is_string($c->getHeading())) {
                $out[] = [$c->getHeading(), $c];
            } elseif ($c instanceof \Filament\Forms\Components\Group || $c instanceof \Filament\Forms\Components\Grid) {
                self::collect($c->getChildComponents(), $out);
            } else {
                $out[] = [null, $c];
            }
        }
    }
}
