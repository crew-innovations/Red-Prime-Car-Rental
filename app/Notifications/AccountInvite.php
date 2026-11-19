<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Notifications\Concerns\RoutedMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email with the "choose your password" link. The link itself is the only secret. */
class AccountInvite extends Notification
{
    use RoutedMail;

    public function __construct(public string $url, public int $hours, public string $kind) {}

    public function mailType(object $notifiable): string
    {
        return $this->kind === 'customer' ? 'customer_sales' : 'driver';
    }

    public function emailKind(): string
    {
        return $this->kind === 'customer' ? 'Customer login link' : 'Driver login link';
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = Setting::read('company_name', 'RED PRIME RENTALS');
        $customer = $this->kind === 'customer' && $notifiable instanceof \App\Models\User ? $notifiable->customer : null;
        // 19 Nov: documents first: the email names what to upload after choosing a password (no separate link).
        $needed = $customer && \App\Support\CustomerArea::documentsGateOn()
            ? collect(\App\Support\CustomerArea::requiredDocumentsFor($customer))->reject(fn (array $row) => $row[2])->map(fn (array $row) => $row[0])->values()->all()
            : [];
        if ($customer && in_array(strtolower((string) $customer->language), ['ar', 'arabic'], true)) {
            // 4 Nov (13.4): the welcome email in Arabic for customers who read Arabic.
            $mail = (new MailMessage)
                ->subject("حسابك لدى {$company}")
                ->greeting('مرحباً '.strtok((string) $notifiable->name, ' ').'،')
                ->line("يمكنك الآن متابعة حجوزاتك وعروض الأسعار والمستندات والمدفوعات مع {$company} عبر الإنترنت.")
                ->action('اختر كلمة المرور', $this->url)
                ->line("يعمل هذا الرابط لمدة {$this->hours} ساعة ولمرة واحدة فقط. إذا انتهت صلاحيته، اطلب منا رابطاً جديداً.");
            if ($needed) {
                $mail->line('بعد تسجيل الدخول، يرجى رفع هذه المستندات: '.implode('، ', $needed));
            }
            $mail->viewData = ['rtl' => true];

            return $this->routed($mail, $notifiable);
        }

        $mail = (new MailMessage)
            ->subject("Your {$company} login")
            ->greeting('Hi '.strtok((string) $notifiable->name, ' ').',')
            ->line($this->kind === 'customer'
                ? "You can now see your bookings, quotations, documents and payments with {$company} online."
                : "Your {$company} driver login is ready. You will see your delivery and collection jobs there.")
            ->action('Choose your password', $this->url)
            ->line("This link works for {$this->hours} hours and only once. If it has expired, ask us for a new one.");
        if ($needed) {
            $mail->line('After you log in, please upload: '.implode(', ', $needed).'. Your bookings and payments open once our team has approved them.');
        }

        return $this->routed($mail, $notifiable);
    }
}
