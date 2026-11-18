<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 11 Nov (limousine): the customer's ride request arrived; the price is an estimate until an agent confirms it. */
class RideRequestReceived extends Notification
{
    use \App\Notifications\Concerns\RoutedMail, \Illuminate\Queue\SerializesModels;

    public function __construct(public Booking $booking, public string $link, public ?string $loginUrl = null, public string $account = 'none') {}

    public function mailType(object $notifiable): string
    {
        return 'customer_sales';
    }

    public function emailKind(): string
    {
        return 'Ride request '.$this->booking->reference;
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
            ->subject('Ride request '.$b->reference.' received · '.$company)
            ->greeting('Hi '.(strtok((string) ($b->customer?->name ?? ''), ' ') ?: 'there').',')
            ->line('Thank you. We have your ride request '.$b->reference.'.')
            ->line('**'.$b->pickup_location.'** → **'.$b->dropoff_location.'**')
            ->line('Pickup: '.$b->start_at?->format('j M Y, H:i'))
            ->line('Estimated price: **AED '.number_format((float) $b->total, 2).'**'.($b->route_km ? ' ('.rtrim(rtrim(number_format((float) $b->route_km, 1), '0'), '.').' km by road)' : ''))
            ->line('Our team confirms the final price shortly. You then accept and pay on your ride page, and follow your chauffeur live there on the day.')
            ->action('Open my ride', $this->link);
        if ($this->loginUrl && $this->account === 'new') {
            $mail->line('We also made you an account, to see all your bookings: '.$this->loginUrl);
        }

        return $this->routed($mail->salutation('Thank you, '.$company), $notifiable);
    }
}
