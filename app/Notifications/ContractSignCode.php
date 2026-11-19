<?php

namespace App\Notifications;

use App\Models\RentalContract;
use App\Models\Setting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 19 Nov (suggestion 10): the one-time code a customer types before signing the contract by link. */
class ContractSignCode extends Notification
{
    use \App\Notifications\Concerns\RoutedMail, \Illuminate\Queue\SerializesModels;

    public function __construct(public RentalContract $contract, public string $code) {}

    public function mailType(object $notifiable): string
    {
        return 'security';
    }

    public function emailKind(): string
    {
        return 'Contract signing code '.$this->contract->number;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = Setting::read('company_name', 'RED PRIME RENTALS');

        return $this->routed((new MailMessage)
            ->subject('Your code to sign '.$this->contract->number.' · '.$company)
            ->greeting('Hi '.(strtok((string) $this->contract->customer?->name, ' ') ?: 'there').',')
            ->line('Your code to sign rental agreement '.$this->contract->number.' is:')
            ->line('**'.$this->code.'**')
            ->line('It works for 10 minutes. If you did not ask for it, please call us.')
            ->salutation('Thank you, '.$company), $notifiable);
    }
}
