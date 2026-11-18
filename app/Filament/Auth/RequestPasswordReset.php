<?php

namespace App\Filament\Auth;

use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset as Base;
use Illuminate\Support\Facades\Password;

/**
 * "Forgot password" (4 Nov, 13.3): the same answer whether or not an account exists, so the page
 * cannot be used to find out who is a customer. The email has a link, never a password.
 */
class RequestPasswordReset extends Base
{
    protected function getFailureNotification(string $status): ?Notification
    {
        if (in_array($status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            $this->form->fill();

            return $this->getSentNotification(Password::RESET_LINK_SENT);
        }

        return parent::getFailureNotification($status);
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return Notification::make()
            ->title('If an account exists for this email, we sent a link to it.')
            ->body('Open the email and choose a new password. The link works for 60 minutes.')
            ->success();
    }
}
