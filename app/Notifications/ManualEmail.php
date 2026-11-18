<?php

namespace App\Notifications;

use App\Notifications\Concerns\RoutedMail;
use App\Support\Documents\Pdf;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * An email written in the CRM (4 Nov, 14.3): "Send email" on a record, or a group announcement.
 * PDFs of the record are made when it is sent. Marketing emails carry an unsubscribe link.
 */
class ManualEmail extends Notification
{
    use RoutedMail, SerializesModels;

    /**
     * @param  array<int, array{0: string, 1: int}>  $pdfs  [kind, id] — quotation / confirmation / statement / invoice
     */
    public function __construct(
        public string $subject,
        public string $body,
        public string $type = 'customer_sales',
        public string $kind = 'Email from the CRM',
        public array $pdfs = [],
        public ?string $unsubscribeUrl = null,
        public bool $rtl = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function mailType(object $notifiable): string
    {
        return $this->type;
    }

    public function emailKind(): string
    {
        return $this->kind;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting(' ');
        foreach (preg_split('/\R{2,}/u', trim($this->body)) as $para) {
            $mail->line(new \Illuminate\Support\HtmlString(nl2br(e($para))));
        }
        $mail->salutation(\App\Support\Mail\Branding::name());
        foreach ($this->pdfs as [$kind, $id]) {
            if ($file = self::pdf($kind, (int) $id)) {
                $mail->attachData($file[1], $file[0], ['mime' => 'application/pdf']);
            }
        }
        if ($this->unsubscribeUrl) {
            $mail->line(new \Illuminate\Support\HtmlString('<span style="font-size:12px;color:#8b8b93">You get this because you agreed to hear from us. <a href="'.e($this->unsubscribeUrl).'">Unsubscribe</a>.</span>'));
            $url = $this->unsubscribeUrl;
            $mail->withSymfonyMessage(fn ($m) => $m->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$url.'>'));
        }
        if ($this->rtl) {
            $mail->viewData = ['rtl' => true];
        }

        return $this->routed($mail, $notifiable);
    }

    /** @return array{0: string, 1: string}|null [file name, bytes] */
    public static function pdf(string $kind, int $id): ?array
    {
        if (! Pdf::available()) {
            return null;
        }

        return match ($kind) {
            'quotation' => ($q = \App\Models\Quotation::find($id)) ? ['Quotation '.$q->number.'.pdf', Pdf::render('pdf.quotation', ['q' => $q])] : null,
            'confirmation' => ($b = \App\Models\Booking::find($id)) ? ['Booking '.$b->reference.'.pdf', Pdf::render('pdf.confirmation', ['b' => $b])] : null,
            'statement' => ($b = \App\Models\Booking::find($id)) && $b->delivered_at ? ['Statement '.$b->reference.'.pdf', Pdf::render('pdf.statement', ['b' => $b])] : null,
            'invoice' => ($i = \App\Models\Invoice::find($id)) ? [$i->title().' '.$i->number.'.pdf', Pdf::render('pdf.invoice', ['i' => $i])] : null,
            default => null,
        };
    }
}
