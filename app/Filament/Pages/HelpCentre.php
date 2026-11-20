<?php

namespace App\Filament\Pages;

use App\Support\HelpGuides;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** 20 Nov (item 12): every help guide of the CRM (and the customer account) with search. Everyone. */
class HelpCentre extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $title = 'Help centre';

    protected static ?string $slug = 'help';

    protected static string $view = 'filament.pages.help-centre';

    protected static bool $shouldRegisterNavigation = false;

    /** Guide to open (?guide=leads). */
    #[Url(as: 'guide')]
    public ?string $open = null;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getSubheading(): ?string
    {
        return 'How each page of the CRM works: what it is for, step-by-step how-tos, what the buttons do, who may use them and what happens next. The "Help" button on every page opens its own guide.';
    }

    public function guides(): array
    {
        $guides = HelpGuides::all();
        unset($guides['help']);
        if (auth()->user()?->isDriverOnly()) {
            return array_intersect_key($guides, array_flip(['driver', 'notifications']));
        }
        $guides['portal'] = ['title' => 'Customer account (what customers see)'] + HelpGuides::portal();

        return $guides;
    }
}
