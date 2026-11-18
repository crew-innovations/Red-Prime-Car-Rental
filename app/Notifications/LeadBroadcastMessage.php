<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Support\Leads\LeadBroadcast;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * 5 Nov (S3, B.1 / B.7): "New lead · Rolls-Royce Cullinan · 3 days · Ahmed", sent to every agent.
 *  - bell: Accept button (claims straight away from the CRM) and View;
 *  - email: one big "Accept this lead" button that opens a small page (a mail scanner opening the
 *    link never accepts anything: only the button on that page does);
 *  - phone: an Accept button where the phone shows them; tapping the notice opens the same page.
 */
class LeadBroadcastMessage extends CrmMessage
{
    public int $leadId;

    public function __construct(Lead $lead, public string $token, public bool $reminder = false)
    {
        $this->leadId = $lead->id;
        parent::__construct(
            ($reminder ? 'Still waiting: ' : '').LeadBroadcast::summary($lead),
            self::details($lead),
            route('lead-claim.show', ['token' => $token]),
            false,
            'heroicon-o-sparkles',
            $reminder ? 'warning' : 'danger',
            'lead_broadcast',
        );
    }

    protected static function details(Lead $lead): string
    {
        return collect([
            $lead->preferred_date ? 'From '.$lead->preferred_date->format('d M').($lead->end_date ? ' to '.$lead->end_date->format('d M') : '') : null,
            $lead->guests ? $lead->guests.' guests' : null,
            $lead->leadSource?->name ? 'Source: '.$lead->leadSource->name : null,
            'First to accept owns it',
        ])->filter()->implode(' · ');
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->icon($this->icon)
            ->iconColor($this->color)
            ->viewData(['lead_claim' => $this->leadId, 'event' => 'lead_broadcast'])
            ->actions([
                Action::make('accept')->label('Accept')->button()->color('danger')
                    ->dispatch('rp-claim-lead', ['lead' => $this->leadId])->close(),
                Action::make('view')->label('View')->link()->color('gray')->url($this->url),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting($this->reminder ? 'This lead is still waiting' : 'A new lead is waiting')
            ->line($this->title)
            ->line($this->body)
            ->action('Accept this lead', $this->url)
            ->line('The first agent to accept owns the lead. The link works once, for you only, for 24 hours.');

        return $this->routed($mail, $notifiable);
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => mb_strimwidth($this->title, 0, 80, '…'),
            'body' => 'First to accept owns it',
            'url' => $this->url,
            'tag' => 'lead-'.$this->leadId,
            'actions' => [['action' => 'accept', 'title' => 'Accept']],
            'claim' => route('lead-claim.accept', ['token' => $this->token]).'?via=push',
        ];
    }
}
