<?php

namespace App\Filament\Account\Pages;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile;

/** Customers change their password here; name and email are kept by our team. */
class AccountProfile extends EditProfile
{
    public function form(Form $form): Form
    {
        return $form->schema([
            Placeholder::make('who')
                ->label('Logged in as')
                ->content(fn (): string => auth()->user()->name.' · '.auth()->user()->email),
            Placeholder::make('change')
                ->hiddenLabel()
                ->content('To change your name, email or phone, please contact us.'),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }
}
