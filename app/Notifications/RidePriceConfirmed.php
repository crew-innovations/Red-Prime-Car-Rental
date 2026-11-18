<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 11 Nov (limousine): the agent confirmed the ride's price; the customer opens their ride page to accept and pay. */
class RidePriceConfirmed extends Notification
{
    use \App\Notifications\Concerns\RoutedMail, \Illuminate\Queue\SerializesModels;

    public function __construct(public Booking $booking, public string $link, public ?string $note = null) {}

    public function mailType(object $notifiable): string
    {
        return 'customer_sales';
    }

    public function emailKind(): string
    {
        return 'Ride price '.$this->booking->reference;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $b = $this->booking;
        $company = Setting::read('company_name', 'RED PRIME RENTALS');
        $mail = (new MailMessage)
            ->subject('Your ride '.$b->reference.': price confirmed · '.$company)
            ->greeting('Hi '.(strtok((string) ($b->customer?->name ?? ''), ' ') ?: 'there').',')
            ->line('We have confirmed the price for your ride.')
            ->line('**'.$b->pickup_location.'** → **'.$b->dropoff_location.'**')
            ->line('Pickup: '.$b->start_at?->format('j M Y, H:i'))
            ->line('Total: **AED '.number_format((float) $b->total + (float) $b->charge_lines_total, 2).'**');
        if (filled($this->note)) {
            $mail->line($this->note);
        }

        return $this->routed($mail->action('Accept and pay', $this->link)
            ->line('On the same page you can follow your chauffeur live on the day of the ride.')
            ->salutation('Thank you, '.$company), $notifiable);
    }
}
