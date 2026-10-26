<?php

namespace App\Notifications;

use App\Models\Setting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email with the "choose your password" link. The link itself is the only secret. */
class AccountInvite extends Notification
{
    public function __construct(public string $url, public int $hours, public string $kind) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = Setting::read('company_name', 'RED PRIME RENTALS');

        return (new MailMessage)
            ->subject("Your {$company} login")
            ->greeting('Hi '.strtok((string) $notifiable->name, ' ').',')
            ->line($this->kind === 'customer'
                ? "You can now see your bookings, quotations, documents and payments with {$company} online."
                : "Your {$company} driver login is ready. You will see your delivery and collection jobs there.")
            ->action('Choose your password', $this->url)
            ->line("This link works for {$this->hours} hours and only once. If it has expired, ask us for a new one.");
    }
}
