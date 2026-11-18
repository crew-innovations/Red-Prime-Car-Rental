<?php

namespace App\Notifications\Concerns;

use App\Support\Mail\MailRouter;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Emails that go out through the mailbox chosen in Settings › Email accounts (4 Nov, 14.2)
 * and are followed in the email log. Notifier sets $emailLogId when it queues the email.
 */
trait RoutedMail
{
    public ?int $emailLogId = null;

    /** Which routing type this email is (MailRouter::TYPES). */
    abstract public function mailType(object $notifiable): string;

    /** Short name for the email log. */
    public function emailKind(): string
    {
        return \Illuminate\Support\Str::headline(class_basename(static::class));
    }

    protected function routed(MailMessage $mail, object $notifiable): MailMessage
    {
        return MailRouter::apply($mail, $this->mailType($notifiable), $this->emailLogId);
    }
}
