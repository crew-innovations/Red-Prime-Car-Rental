<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Setting;
use App\Support\Accounts;
use App\Support\Site\WebsiteBooking;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email to the customer after a website booking (30 Oct): the booking, that it waits for
 * payment, and their login (a "choose your password" link, never a password).
 */
class WebsiteBookingReceived extends Notification
{
    use \App\Notifications\Concerns\RoutedMail, \Illuminate\Queue\SerializesModels;

    public function mailType(object $notifiable): string
    {
        return 'customer_sales';
    }

    public function emailKind(): string
    {
        return 'Website booking '.$this->booking->reference;
    }

    public function __construct(
        public Booking $booking,
        public ?string $loginUrl,
        public string $account, // new / existing / none
        public string $lang = 'en',
        public ?string $loginEmail = null,
        public ?string $payUrl = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $l = in_array($this->lang, ['en', 'ar'], true) ? $this->lang : 'en';
        $t = fn (string $key, array $r = []) => __('site.mail.'.$key, $r, $l);
        $b = $this->booking;
        $company = Setting::read('company_name', 'RED PRIME RENTALS');
        $fmt = fn ($d) => $d?->locale($l)->translatedFormat('j M Y, H:i');
        $name = strtok((string) ($b->customer?->name ?? ''), ' ') ?: '';

        $mail = (new MailMessage)
            ->subject($t('subject', ['ref' => $b->reference, 'company' => $company]))
            ->greeting($t('hello', ['name' => $name]))
            ->line($t('intro', ['ref' => $b->reference]))
            ->line('**'.$b->title().'**')
            ->line($t('dates', ['from' => $fmt($b->start_at), 'to' => $fmt($b->end_at)]));
        if ($b->guests) {
            $mail->line($t('guests', ['n' => $b->guests]));
        }
        $mail->line($t('total', ['amount' => 'AED '.number_format((float) $b->total, 2)]));
        if ($this->payUrl) {
            $mail->line($t('pay_link', ['url' => $this->payUrl]))
                ->action($t('pay_action'), $this->payUrl);
        } else {
            $mail->line($t('payment'));
        }
        if ($b->hold_until) {
            $mail->line($t('hold', ['hours' => WebsiteBooking::holdHours(), 'until' => $fmt($b->hold_until)]));
        }

        if ($this->loginUrl && $this->account === 'new') {
            $mail->line($t('account_new', ['email' => $this->loginEmail]))
                ->action($t('choose_password'), $this->loginUrl)
                ->line($t('link_hours', ['hours' => Accounts::linkHours()]));
        } elseif ($this->loginUrl) {
            $mail->line($t('account_existing', ['email' => $this->loginEmail]))
                ->action($t('open_account'), $this->loginUrl);
        }

        $mail->line($t('questions'))->salutation($t('thanks', ['company' => $company]));
        if ($l === 'ar') {
            $mail->viewData = ['rtl' => true];
        }

        return $this->routed($mail, $notifiable);
    }
}
