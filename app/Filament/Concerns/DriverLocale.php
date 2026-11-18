<?php

namespace App\Filament\Concerns;

/** Driver screens in the driver's language, on the first load and on every Livewire update. */
trait DriverLocale
{
    public function bootDriverLocale(): void
    {
        \App\Support\Drivers\DriverLang::apply(auth()->user());
    }
}
