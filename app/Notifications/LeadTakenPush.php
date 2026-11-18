<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Notifications\Channels\WebPushChannel;
use App\Support\Notifications\Preferences;
use Illuminate\Notifications\Notification;

/**
 * 5 Nov (S3): replaces the "new lead" phone notice of the agents who didn't get the lead
 * (same tag) with "Taken by …". Phone only: the bell row is changed in place.
 */
class LeadTakenPush extends Notification
{
    public string $event = 'lead_broadcast';

    public function __construct(public Lead $lead, public string $ownerName) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof \App\Models\User && in_array('push', Preferences::channels($notifiable, 'lead_broadcast', ['push']), true)
            ? [WebPushChannel::class] : [];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'Taken by '.$this->ownerName,
            'body' => mb_strimwidth($this->lead->name, 0, 80, '…'),
            'url' => url('/crm'),
            'tag' => 'lead-'.$this->lead->id,
            'silent' => true,
        ];
    }
}
