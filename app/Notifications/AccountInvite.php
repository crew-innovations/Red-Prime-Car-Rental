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
        if ($customer && in_array(strtolower((string) $customer->language), ['ar', 'arabic'], true)) {
            // 4 Nov (13.4): the welcome email in Arabic for customers who read Arabic.
            $mail = (new MailMessage)
                ->subject("حسابك لدى {$company}")
                ->greeting('مرحباً '.strtok((string) $notifiable->name, ' ').'،')
                ->line("يمكنك الآن متابعة حجوزاتك وعروض الأسعار والمستندات والمدفوعات مع {$company} عبر الإنترنت.")
                ->action('اختر كلمة المرور', $this->url)
                ->line("يعمل هذا الرابط لمدة {$this->hours} ساعة ولمرة واحدة فقط. إذا انتهت صلاحيته، اطلب منا رابطاً جديداً.");
            $mail->viewData = ['rtl' => true];

            return $this->routed($mail, $notifiable);
        }

        return $this->routed((new MailMessage)
            ->subject("Your {$company} login")
            ->greeting('Hi '.strtok((string) $notifiable->name, ' ').',')
            ->line($this->kind === 'customer'
                ? "You can now see your bookings, quotations, documents and payments with {$company} online."
                : "Your {$company} driver login is ready. You will see your delivery and collection jobs there.")
            ->action('Choose your password', $this->url)
            ->line("This link works for {$this->hours} hours and only once. If it has expired, ask us for a new one."), $notifiable);
    }
}
