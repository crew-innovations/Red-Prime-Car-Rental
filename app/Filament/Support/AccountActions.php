<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Support\Access;
use App\Support\Accounts;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/** "Send login link" and "switch login on / off", used on driver and customer pages. */
class AccountActions
{
    /** @param \Closure(): ?User $user resolves (or creates) the login to invite */
    public static function sendLink(\Closure $user, string $label = 'Send login link', ?\Closure $visible = null): Action
    {
        return Action::make('sendLoginLink')
            ->label($label)
            ->icon('heroicon-o-key')
            ->color('gray')
            ->authorize(fn (): bool => Access::allows('manage-accounts'))
            ->visible($visible ?? fn (): bool => true)
            ->modalHeading($label)
            ->modalDescription(new HtmlString('They open the link and choose their own password. The link works once, for '.Accounts::linkHours().' hours. Sending a new link also helps someone who forgot their password.'))
            ->modalSubmitActionLabel('Create link')
            ->form([
                Forms\Components\Toggle::make('email')
                    ->label('Also email the link to them')
                    ->helperText('Only works when email sending is set up for the CRM. You can always send it by WhatsApp.')
                    ->default(false),
            ])
            ->action(function (array $data) use ($user): void {
                /** @var User $account */
                $account = $user();
                $url = Accounts::inviteUrl($account);
                $mailed = false;
                if (! empty($data['email'])) {
                    try {
                        Accounts::emailInvite($account, $url);
                        $mailed = true;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }

                $wa = Accounts::whatsappUrl($account, $url);
                Notification::make()
                    ->title('Login link ready for '.$account->name)
                    ->body(new HtmlString(($mailed ? 'Emailed to '.e($account->email).'. ' : (! empty($data['email']) ? 'The email could not be sent. ' : ''))
                        .'Send this link to them:<br><code style="word-break:break-all;user-select:all;font-size:.8rem">'.e($url).'</code>'))
                    ->success()
                    ->persistent()
                    ->actions(array_values(array_filter([
                        $wa ? NotificationAction::make('wa')->label('Send by WhatsApp')->url($wa, shouldOpenInNewTab: true)->button() : null,
                    ])))
                    ->send();
            });
    }

    public static function toggleLogin(\Closure $user, ?\Closure $visible = null): Action
    {
        return Action::make('toggleLogin')
            ->label(fn (): string => $user()?->login_enabled ? 'Switch login off' : 'Switch login on')
            ->icon(fn (): string => $user()?->login_enabled ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
            ->color(fn (): string => $user()?->login_enabled ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => $user()?->login_enabled
                ? 'They lose access at once and cannot log in. Nothing is deleted.'
                : 'They can log in again with their password (or a new link).')
            ->authorize(fn (): bool => Access::allows('manage-accounts'))
            ->visible($visible ?? fn (): bool => $user() !== null)
            ->action(function () use ($user): void {
                $account = $user();
                $account->update(['login_enabled' => ! $account->login_enabled]);
                Notification::make()->title($account->login_enabled ? 'Login switched on' : 'Login switched off')->success()->send();
            });
    }
}
