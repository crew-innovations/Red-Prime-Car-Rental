<?php

namespace App\Notifications;

use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A short in-app message (the bell in the top bar), optionally also by email.
 * Used for driver jobs, damage reports, document expiry and customer uploads.
 */
class CrmMessage extends Notification
{
    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $url = null,
        public bool $mail = false,
        public string $icon = 'heroicon-o-bell',
        public string $color = 'info',
    ) {}

    public function via(object $notifiable): array
    {
        return $this->mail && filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $message = FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->icon($this->icon)
            ->iconColor($this->color);

        if ($this->url) {
            $message->actions([Action::make('open')->label('Open')->url($this->url)->markAsRead()]);
        }

        return $message->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->title);
        if ($this->body) {
            $mail->line($this->body);
        }

        return $this->url ? $mail->action('Open', $this->url) : $mail;
    }
}
