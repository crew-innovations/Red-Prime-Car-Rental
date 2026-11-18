<?php

namespace App\Notifications;

use App\Models\Quotation;
use App\Models\Setting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The quotation link by email (28 Oct, 3.3). The customer views, downloads and accepts it there. */
class QuotationSent extends Notification
{
    use \App\Notifications\Concerns\RoutedMail, \Illuminate\Queue\SerializesModels;

    /** @param  ?string  $loginUrl  4 Nov (13.1): "choose your password" link when the quotation made the customer's account */
    public function __construct(public Quotation $quotation, public ?string $loginUrl = null) {}

    public function mailType(object $notifiable): string
    {
        return 'customer_sales';
    }

    public function emailKind(): string
    {
        return 'Quotation '.$this->quotation->number;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $q = $this->quotation;
        $company = Setting::read('company_name', 'RED PRIME RENTALS');
        $name = strtok((string) $q->customerName(), ' ') ?: 'there';

        return $this->routed((new MailMessage)
            ->subject("Your quotation {$q->number} from {$company}")
            ->greeting("Hi {$name},")
            ->line('Here is your quotation for '.($q->bookable?->name ?? $q->service_type?->getLabel()).($q->start_at ? ', '.$q->start_at->format('D d M Y, H:i') : '').'.')
            ->line('Total: AED '.number_format((float) $q->total, 2).($q->valid_until ? ' · valid until '.$q->valid_until->format('d M Y') : '').'.')
            ->action('View and accept', $q->publicUrl())
            ->line('You can download it as a PDF from the same page.')
            ->when($this->loginUrl, fn (MailMessage $m) => $m->line(new \Illuminate\Support\HtmlString(
                'You can also follow your quotations and bookings in your own account: <a href="'.e($this->loginUrl).'">choose your password</a> (the link works for '.\App\Support\Accounts::linkHours().' hours).'))), $notifiable);
    }
}
