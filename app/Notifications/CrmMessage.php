<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Concerns\RoutedMail;
use App\Support\Notifications\Events;
use App\Support\Notifications\Preferences;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * A short notice: the bell in /crm and /account, and (4 Nov, Phase 2) push to the phone or
 * desktop and email, as each person's preferences allow for the event. Drivers never get
 * money amounts; pushes carry as little personal data as possible.
 */
class CrmMessage extends Notification
{
    use RoutedMail, SerializesModels;

    /** Channels for this person, worked out by prepareFor(); null = before 4 Nov behaviour. */
    public ?array $channels = null;

    /**
     * 5 Nov (S3, B.8): buttons in the bell that do the job right there (Approve…), each
     * [name, label, browser event, event data, colour]. Handled by App\Livewire\LeadClaimListener.
     */
    public array $quickActions = [];

    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $url = null,
        public bool $mail = false,
        public string $icon = 'heroicon-o-bell',
        public string $color = 'info',
        public string $event = 'other',
        public bool $customerMail = true,
    ) {
        $this->event = Events::valid($event);
    }

    /** Called by Notifier for each person, on a copy of the notice. */
    public function prepareFor(object $to): void
    {
        if (! $to instanceof User) {
            $this->channels = ['mail'];

            return;
        }
        if ($to->isDriverOnly()) {
            // Drivers never see money (brief 9.4): any amount is taken out.
            $strip = fn (?string $t) => $t === null ? null : trim((string) preg_replace('/\s*·?\s*(AED|Dhs?\.?)\s?-?[\d,]+(\.\d+)?/iu', '', $t));
            $this->title = $strip($this->title);
            $this->body = $strip($this->body);
        }
        $wanted = ['database', 'push', 'mail'];
        if ($to->isCustomerAccount() && ! $this->customerMail) {
            $wanted = ['database', 'push'];
        }
        $this->channels = Preferences::channels($to, $this->event, $wanted);
    }

    public function via(object $notifiable): array
    {
        $channels = $this->channels
            ?? ($this->mail && filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database']);
        if (in_array('mail', $channels, true) && $notifiable instanceof User && blank($notifiable->email)) {
            $channels = array_diff($channels, ['mail']);
        }

        return array_values(array_map(fn ($c) => $c === 'push' ? WebPushChannel::class : $c, array_unique($channels)));
    }

    public function mailType(object $notifiable): string
    {
        return match (true) {
            $notifiable instanceof User && $notifiable->isCustomerAccount() => 'customer_sales',
            $notifiable instanceof User && $notifiable->isDriverOnly() => 'driver',
            $notifiable instanceof User => 'staff',
            default => 'customer_sales',
        };
    }

    public function emailKind(): string
    {
        return Events::label($this->event);
    }

    public function toDatabase(object $notifiable): array
    {
        $message = FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->icon($this->icon)
            ->iconColor($this->color)
            ->viewData(['event' => $this->event]); // 5 Nov (S3): the bell filters by type

        $actions = collect($this->quickActions)->map(fn ($a) => Action::make($a[0])->label($a[1])->button()->color($a[4] ?? 'primary')
            ->dispatch($a[2], $a[3] ?? [])->markAsRead()->close())->all();
        if ($this->url) {
            $actions[] = Action::make('open')->label('Open')->url($this->url)->markAsRead()->link();
        }
        if ($actions) {
            $message->actions($actions);
        }

        return $message->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->title);
        if ($this->body) {
            $mail->line($this->body);
        }
        if ($this->url) {
            $mail->action('Open', $this->url);
        }

        return $this->routed($mail, $notifiable);
    }

    /** Lock-screen text: the title and a short line, without addresses (after " · "). */
    public function toWebPush(object $notifiable): array
    {
        $body = $this->body ? preg_replace('/\s·\s.*$/u', '', $this->body) : null;

        return [
            'title' => mb_strimwidth($this->title, 0, 80, '…'),
            'body' => $body ? mb_strimwidth($body, 0, 110, '…') : null,
            'url' => $this->url,
            'tag' => $this->event,
        ];
    }
}
