<?php

namespace App\Support\Chat;

use App\Enums\LeadStatus;
use App\Filament\Pages\Messages;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use App\Support\Drivers\Notify;
use App\Support\Leads\LeadIntake;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * 12 Nov (Bilal): a customer writes on Instagram, Facebook Messenger or WhatsApp → the message
 * arrives here (Meta webhook, signed with the app secret), a new conversation becomes a lead that
 * goes to the agents as every lead does, and the agent who has the lead carries on the chat in
 * CRM › Messages: what they type is sent to the customer on the same channel.
 *
 * One chat thread per person per channel (chat_threads.channel + external_id). Each message is
 * saved once (chat_messages.external_id = Meta's message id; Meta resends webhooks).
 * Meta's rule: a reply is free text only within 24 hours of the customer's last message
 * (Instagram / Messenger: up to 7 days with the "human agent" tag; WhatsApp: only an approved
 * template after 24 hours, which is not built yet).
 */
class Social
{
    public const PROVIDER = 'meta_messages';

    public const CHANNELS = ['instagram' => 'Instagram', 'messenger' => 'Facebook Messenger', 'whatsapp' => 'WhatsApp'];

    public static function integration(): Integration
    {
        return Integration::for(self::PROVIDER);
    }

    protected static function graph(Integration $i): string
    {
        return 'https://graph.facebook.com/'.($i->config('graph_version') ?: 'v21.0');
    }

    public static function registryEntries(): array
    {
        return [self::PROVIDER => [
            'label' => 'Instagram, Facebook & WhatsApp messages', 'group' => 'Messages', 'tester' => self::class, 'webhook' => '/api/webhooks/messages/meta',
            'description' => 'A customer\'s message becomes a lead; the agent who accepts it carries on the chat in CRM › Messages and the reply goes back on the same channel.',
            'docs' => 'https://developers.facebook.com/docs/messenger-platform',
            'config' => [
                'whatsapp_phone_number_id' => ['label' => 'WhatsApp phone number ID', 'help' => 'Only for WhatsApp: WhatsApp Manager › API setup.'],
                'graph_version' => ['label' => 'Graph API version', 'help' => 'e.g. v21.0', 'default' => 'v21.0'],
                // 19 Nov (suggestion 9): approved WhatsApp templates for messages the CRM sends by itself.
                'wa_template_contract' => ['label' => 'WhatsApp template: contract to sign', 'help' => 'Approved template name. Body with 3 values: {{1}} name, {{2}} contract number, {{3}} link.'],
                'wa_template_invoice' => ['label' => 'WhatsApp template: invoice', 'help' => 'Approved template name. Body with 3 values: {{1}} name, {{2}} invoice number and amount, {{3}} link.'],
                'wa_template_language' => ['label' => 'WhatsApp template language', 'help' => 'The language code the templates were approved in, e.g. en or en_US.', 'default' => 'en'],
            ],
            'secrets' => [
                'app_secret' => ['label' => 'App secret', 'help' => 'Meta app › App settings › Basic. Used to check every message that arrives.'],
                'verify_token' => ['label' => 'Verify token', 'help' => 'Any long random text; type the same in the Meta webhook settings.'],
                'page_access_token' => ['label' => 'Page access token (long-lived)', 'help' => 'For Instagram and Messenger replies. Needs instagram_manage_messages, pages_messaging, pages_manage_metadata.'],
                'whatsapp_token' => ['label' => 'WhatsApp access token (permanent)', 'help' => 'Only for WhatsApp: a system user token with whatsapp_business_messaging.'],
            ],
            'required' => ['app_secret', 'verify_token'],
            'requirements' => [
                'An Instagram professional account linked to the Facebook Page, with "Allow access to messages" switched on in Instagram.',
                'A Meta app (Business type) with Messenger, Instagram and (if used) WhatsApp products; instagram_manage_messages and pages_messaging approved by Meta (App Review) and the business verified.',
                'Webhooks: subscribe "messages" for Instagram, Page and WhatsApp Business Account to the CRM address below, with the verify token.',
                'WhatsApp only: a number on the WhatsApp Business Platform (Meta charges per conversation).',
            ],
        ]];
    }

    /** Settings › Integrations › Test connection. */
    public static function test(Integration $i): array
    {
        foreach (['app_secret' => 'App secret', 'verify_token' => 'Verify token'] as $k => $label) {
            if (! $i->secret($k)) {
                return [false, $label.' not saved'];
            }
        }
        if (! $i->secret('page_access_token') && ! $i->secret('whatsapp_token')) {
            return [false, 'Save the Page access token (Instagram / Messenger) or the WhatsApp token: without one, replies cannot be sent.'];
        }
        $found = [];
        try {
            if ($i->secret('page_access_token')) {
                $res = Http::timeout(15)->acceptJson()->get(self::graph($i).'/me', ['access_token' => $i->secret('page_access_token'), 'fields' => 'id,name']);
                if ($res->failed()) {
                    return [false, 'Page token: '.data_get($res->json(), 'error.message', 'HTTP '.$res->status())];
                }
                $found[] = 'Page: '.data_get($res->json(), 'name');
            }
            if ($i->secret('whatsapp_token')) {
                if (blank($i->config('whatsapp_phone_number_id'))) {
                    return [false, 'WhatsApp phone number ID not set'];
                }
                $res = Http::timeout(15)->acceptJson()->withToken($i->secret('whatsapp_token'))->get(self::graph($i).'/'.urlencode((string) $i->config('whatsapp_phone_number_id')), ['fields' => 'display_phone_number,verified_name']);
                if ($res->failed()) {
                    return [false, 'WhatsApp: '.data_get($res->json(), 'error.message', 'HTTP '.$res->status())];
                }
                $found[] = 'WhatsApp: '.data_get($res->json(), 'display_phone_number');
            }
        } catch (\Throwable $e) {
            return [false, 'Could not reach Meta: '.class_basename($e)];
        }

        return [true, null, implode(' · ', $found)];
    }

    /** GET: Meta checks the address ("hub.challenge"). @return array{0: string, 1: int} */
    public static function challenge(Request $request): array
    {
        $i = self::integration();
        $token = $i->secret('verify_token');
        $ok = $request->query('hub_mode') === 'subscribe' && $token && hash_equals($token, (string) $request->query('hub_verify_token'));
        IntegrationEvent::log(self::PROVIDER, 'in', $ok ? 'ok' : 'rejected', 'verify address', null, [], $ok ? null : 'Verify token did not match');

        return $ok ? [(string) $request->query('hub_challenge'), 200] : ['', 403];
    }

    /** POST: messages from Meta. @return array{0: array, 1: int} */
    public static function receive(Request $request): array
    {
        $i = self::integration();
        if (! $i->isReady()) {
            IntegrationEvent::log(self::PROVIDER, 'in', 'rejected', 'message webhook', null, [], 'Not set up or switched off');

            return [['received' => false], 404];
        }
        $raw = $request->getContent();
        $given = preg_replace('/^sha256=/i', '', trim((string) $request->header('X-Hub-Signature-256')));
        if ($given === '' || ! hash_equals(hash_hmac('sha256', $raw, (string) $i->secret('app_secret')), strtolower($given))) {
            IntegrationEvent::log(self::PROVIDER, 'in', 'rejected', 'message webhook', null, ['ip' => $request->ip()], 'X-Hub-Signature-256 does not match');

            return [['received' => false], 400];
        }

        $done = [];
        foreach (self::parse((array) json_decode($raw, true)) as $in) {
            try {
                $done[] = self::ingest($in);
            } catch (\Throwable $e) {
                report($e);
                IntegrationEvent::log(self::PROVIDER, 'in', 'failed', 'message', null, ['channel' => $in['channel']], $e->getMessage());

                return [['received' => false], 500]; // Meta sends it again
            }
        }

        return [['received' => true, 'messages' => $done], 200];
    }

    /**
     * Meta's three webhook shapes → one list.
     *
     * @return list<array{channel: string, from: string, id: string, text: ?string, attachment: ?string, kind: ?string, at: ?int, name: ?string}>
     */
    public static function parse(array $body): array
    {
        $out = [];
        $object = $body['object'] ?? null;
        foreach ((array) ($body['entry'] ?? []) as $entry) {
            if ($object === 'instagram' || $object === 'page') {
                foreach ((array) ($entry['messaging'] ?? []) as $m) {
                    $msg = (array) ($m['message'] ?? []);
                    // Our own replies come back as "echo"; reads, reactions and deleted messages carry no new text.
                    if (! $msg || ! empty($msg['is_echo']) || ! empty($msg['is_deleted']) || blank($m['sender']['id'] ?? null) || blank($msg['mid'] ?? null)) {
                        continue;
                    }
                    $att = (array) (($msg['attachments'] ?? [])[0] ?? []);
                    $out[] = ['channel' => $object === 'instagram' ? 'instagram' : 'messenger', 'from' => (string) $m['sender']['id'], 'id' => (string) $msg['mid'],
                        'text' => filled($msg['text'] ?? null) ? (string) $msg['text'] : null, 'attachment' => data_get($att, 'payload.url'), 'kind' => $att['type'] ?? null,
                        'at' => isset($m['timestamp']) ? (int) round($m['timestamp'] / 1000) : null, 'name' => null];
                }
            } elseif ($object === 'whatsapp_business_account') {
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    $v = (array) ($change['value'] ?? []);
                    $names = collect((array) ($v['contacts'] ?? []))->mapWithKeys(fn ($c) => [(string) ($c['wa_id'] ?? '') => data_get($c, 'profile.name')]);
                    foreach ((array) ($v['messages'] ?? []) as $msg) {
                        if (blank($msg['from'] ?? null) || blank($msg['id'] ?? null)) {
                            continue;
                        }
                        $type = (string) ($msg['type'] ?? 'text');
                        $out[] = ['channel' => 'whatsapp', 'from' => (string) $msg['from'], 'id' => (string) $msg['id'],
                            'text' => $type === 'text' ? data_get($msg, 'text.body') : (data_get($msg, $type.'.caption') ?: data_get($msg, 'button.text') ?: data_get($msg, 'interactive.button_reply.title')),
                            'attachment' => null, 'kind' => $type === 'text' ? null : $type,
                            'at' => isset($msg['timestamp']) ? (int) $msg['timestamp'] : null, 'name' => $names[(string) $msg['from']] ?? null];
                    }
                }
            }
        }

        return $out;
    }

    /** One incoming message: thread (and lead when the conversation is new), message, notice. Returns what happened. */
    public static function ingest(array $in): string
    {
        if (ChatMessage::query()->where('external_id', $in['id'])->exists()) {
            return 'duplicate';
        }
        $label = self::CHANNELS[$in['channel']];
        $body = trim((string) ($in['text'] ?? ''));
        if ($body === '') {
            $body = '['.ucfirst((string) ($in['kind'] ?: 'message')).']';
        }
        $body = mb_substr($body, 0, 2000);
        $at = $in['at'] ? Carbon::createFromTimestamp($in['at']) : now();

        try {
            [$thread, $message, $new, $enquiry] = DB::transaction(function () use ($in, $body, $at) {
                $thread = ChatThread::query()->where('channel', $in['channel'])->where('external_id', $in['from'])->lockForUpdate()->first();
                $new = ! $thread;
                $name = $in['name'] ?: ($thread?->external_name ?: self::profileName($in['channel'], $in['from']));
                $thread ??= ChatThread::create(['kind' => 'social', 'channel' => $in['channel'], 'external_id' => $in['from'], 'external_name' => $name]);
                if ($name && $thread->external_name !== $name) {
                    $thread->external_name = $name;
                }

                // A new conversation, or one whose lead is finished (won / lost), is a new enquiry.
                $lead = $thread->lead_id ? Lead::find($thread->lead_id) : null;
                $enquiry = false;
                if (! $lead || in_array($lead->status, [LeadStatus::Converted, LeadStatus::Lost], true)) {
                    $enquiry = true;
                    [$lead] = LeadIntake::receive([
                        'name' => $name ?: (self::CHANNELS[$in['channel']].' user'),
                        'phone' => $in['channel'] === 'whatsapp' ? '+'.ltrim($in['from'], '+') : null,
                        'whatsapp' => $in['channel'] === 'whatsapp' ? '+'.ltrim($in['from'], '+') : null,
                        'message' => $body, 'channel' => $in['channel'], 'source_slug' => $in['channel'], 'source_name' => self::CHANNELS[$in['channel']],
                        'external_id' => $in['from'].($new ? '' : '@'.$at->timestamp),
                        'platform' => ['platform' => $in['channel'], 'sender_id' => $in['from']],
                    ]);
                    $thread->lead_id = $lead->id;
                }
                $thread->customer_id = $lead->customer_id ?: $thread->customer_id;
                $thread->last_message_at = now();
                $thread->last_inbound_at = $at;
                $thread->closed_at = null;
                $thread->save();

                $message = $thread->messages()->create(['external_id' => $in['id'], 'user_id' => null, 'author' => 'customer', 'body' => $body,
                    'attachment_url' => filled($in['attachment']) ? mb_substr((string) $in['attachment'], 0, 1000) : null, 'internal' => false]);

                return [$thread, $message, $new, $enquiry];
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate'; // the same webhook arrived twice at once
        }

        IntegrationEvent::log(self::PROVIDER, 'in', 'ok', 'message', mb_substr($in['id'], 0, 191), ['channel' => $in['channel'], 'thread' => $thread->id, 'lead' => $thread->lead_id]);

        // The agent who has the lead is told. A lead nobody has accepted yet was already offered to every agent.
        $owner = $thread->lead?->assignee;
        if ($owner) {
            rescue(fn () => Notify::person($owner, 'New '.$label.' message: '.$thread->title(), mb_substr($body, 0, 140),
                Messages::getUrl(['thread' => $thread->id], panel: 'admin'), 'info', 'message', 'social:'.$message->id), report: false);
        }

        // 12 Nov (C4): the automatic first reply, or the "we are closed" reply outside working hours.
        rescue(fn () => AutoReply::after($thread->fresh(), $enquiry), report: false);

        return $new ? 'new conversation' : 'message';
    }

    /** Instagram / Messenger: the person's name (best effort; the lead is made without it if Meta says no). */
    protected static function profileName(string $channel, string $id): ?string
    {
        $i = self::integration();
        if ($channel === 'whatsapp' || ! $i->secret('page_access_token')) {
            return null;
        }
        try {
            $res = Http::timeout(8)->acceptJson()->get(self::graph($i).'/'.urlencode($id), ['access_token' => $i->secret('page_access_token'),
                'fields' => $channel === 'instagram' ? 'name,username' : 'first_name,last_name']);
        } catch (\Throwable) {
            return null;
        }
        if ($res->failed()) {
            return null;
        }
        $name = $channel === 'instagram'
            ? (data_get($res->json(), 'name') ?: (data_get($res->json(), 'username') ? '@'.data_get($res->json(), 'username') : null))
            : trim(data_get($res->json(), 'first_name').' '.data_get($res->json(), 'last_name'));

        return filled($name) ? mb_substr((string) $name, 0, 255) : null;
    }

    /** Can the team still write free text to this person? Null = yes, else the reason in plain words. */
    public static function blocked(ChatThread $t): ?string
    {
        $i = self::integration();
        $label = self::CHANNELS[$t->channel] ?? 'this channel';
        if (! $i->isReady()) {
            return $label.' messages are not connected (Settings › Integrations).';
        }
        if ($t->channel === 'whatsapp' ? (! $i->secret('whatsapp_token') || blank($i->config('whatsapp_phone_number_id'))) : ! $i->secret('page_access_token')) {
            return 'The '.$label.' access token is not saved (Settings › Integrations), so replies cannot be sent.';
        }
        $hours = $t->last_inbound_at ? $t->last_inbound_at->diffInHours(now()) : null;
        if ($hours === null) {
            return 'The customer has not written yet. '.$label.' only allows a reply after their message.';
        }
        if ($t->channel === 'whatsapp' && $hours >= 24) {
            return 'More than 24 hours since the customer\'s last WhatsApp message: WhatsApp only allows an approved template now. Ask them to write again, or call them.';
        }
        if ($t->channel !== 'whatsapp' && $hours >= 24 * 7) {
            return 'More than 7 days since the customer\'s last message: '.$label.' does not allow a reply until they write again.';
        }

        return null;
    }

    /** Sends the agent's reply on the customer's channel. Returns Meta's message id. Throws in plain words when it cannot go. */
    public static function send(ChatThread $t, string $body): ?string
    {
        if ($why = self::blocked($t)) {
            throw ValidationException::withMessages(['body' => $why]);
        }
        $i = self::integration();
        try {
            if ($t->channel === 'whatsapp') {
                $res = Http::timeout(20)->acceptJson()->withToken($i->secret('whatsapp_token'))
                    ->post(self::graph($i).'/'.urlencode((string) $i->config('whatsapp_phone_number_id')).'/messages',
                        ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $t->external_id, 'type' => 'text', 'text' => ['preview_url' => true, 'body' => $body]]);
                $id = data_get($res->json(), 'messages.0.id');
            } else {
                // Within 24 hours a normal reply; after that (up to 7 days) Meta wants the "human agent" tag.
                $late = $t->last_inbound_at->diffInHours(now()) >= 24;
                $res = Http::timeout(20)->acceptJson()->post(self::graph($i).'/me/messages?access_token='.urlencode((string) $i->secret('page_access_token')),
                    ['recipient' => ['id' => $t->external_id], 'message' => ['text' => $body]] + ($late ? ['messaging_type' => 'MESSAGE_TAG', 'tag' => 'HUMAN_AGENT'] : ['messaging_type' => 'RESPONSE']));
                $id = data_get($res->json(), 'message_id');
            }
        } catch (\Throwable $e) {
            IntegrationEvent::log(self::PROVIDER, 'out', 'failed', 'send message', null, ['thread' => $t->id], class_basename($e));
            throw ValidationException::withMessages(['body' => 'Could not reach '.self::CHANNELS[$t->channel].'. Nothing was sent: try again.']);
        }
        if ($res->failed()) {
            $error = (string) data_get($res->json(), 'error.message', 'HTTP '.$res->status());
            IntegrationEvent::log(self::PROVIDER, 'out', 'failed', 'send message', null, ['thread' => $t->id, 'http' => $res->status()], $error);
            throw ValidationException::withMessages(['body' => self::CHANNELS[$t->channel].' did not accept the message: '.mb_substr($error, 0, 200)]);
        }
        IntegrationEvent::log(self::PROVIDER, 'out', 'ok', 'send message', null, ['thread' => $t->id, 'channel' => $t->channel]);

        return filled($id) ? mb_substr((string) $id, 0, 191) : null;
    }

    /**
     * 12 Nov (C3): what the agent can drop into the chat with one tap. Each entry is a ready
     * message with the customer's own link; the agent can still edit it before sending.
     *
     * @return array<string, array{label: string, text: string}>
     */
    public static function links(ChatThread $t): array
    {
        $lead = $t->lead;
        if (! $lead) {
            return [];
        }
        $out = [];
        $ref = \App\Support\Transfers\LimoLink::ref($lead);

        $form = \App\Models\LeadForm::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('service_type = ? desc', [$lead->service_type?->value])->orderByDesc('id')->get()->first(fn ($f) => ! $f->closedReason());
        if ($form) {
            $out['form'] = ['label' => 'Details form', 'text' => 'Please fill in your details here, it takes a minute: '.$form->url().'?lr='.$ref];
        }
        if ($lead->service_type === \App\Enums\ServiceType::Transfer || ! $lead->service_type) {
            if (\Illuminate\Support\Facades\Route::has('site.limousine') && \App\Support\Transfers\RideFlow::distanceService()) {
                $out['ride'] = ['label' => 'Limousine booking', 'text' => 'Choose your car, pickup and destination here to see the price and request your ride: '.\App\Support\Transfers\LimoLink::url($lead, [])];
            }
        }
        $quote = $lead->quotations()->whereIn('status', [\App\Enums\QuotationStatus::Sent, \App\Enums\QuotationStatus::Negotiation, \App\Enums\QuotationStatus::Accepted])->latest('id')->first();
        if ($quote && filled($quote->public_token)) {
            $out['quotation'] = ['label' => 'Quotation '.$quote->number, 'text' => 'Here is your quotation '.$quote->number.'. You can view and accept it online: '.$quote->publicUrl()];
        }
        $booking = $lead->bookings()->whereNotIn('status', [\App\Enums\BookingStatus::Cancelled, \App\Enums\BookingStatus::NoShow, \App\Enums\BookingStatus::Completed])->latest('id')->first();
        if ($booking && $booking->service_type === \App\Enums\ServiceType::Transfer) {
            $out['ridepage'] = ['label' => 'Ride page '.$booking->reference, 'text' => $booking->status === \App\Enums\BookingStatus::PendingPayment
                ? 'Your price is confirmed. Accept and pay here, and follow your chauffeur on the same page: '.\App\Support\Transfers\Tracking::link($booking)
                : 'Here is your ride page, where you can follow your booking and your chauffeur live: '.\App\Support\Transfers\Tracking::link($booking)];
        }
        $pay = \App\Models\PaymentRequest::query()->whereIn('status', ['open', 'failed'])->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereIn('booking_id', $lead->bookings()->select('id'))->orWhereIn('quotation_id', $lead->quotations()->select('id')))->latest('id')->first();
        if ($pay) {
            $out['payment'] = ['label' => 'Payment link AED '.number_format((float) $pay->amount, 0), 'text' => 'You can pay AED '.number_format((float) $pay->amount, 2).' securely online here: '.$pay->url()];
        }

        return $out;
    }

    /**
     * A message the CRM sends by itself in the conversation (follow-up reminders). Returns false,
     * and sends nothing, when Meta's window is closed or the channel is not connected.
     */
    public static function system(ChatThread $t, string $body): bool
    {
        if (self::blocked($t)) {
            return false;
        }
        try {
            $id = self::send($t, $body);
        } catch (ValidationException) {
            return false;
        }
        $t->messages()->create(['user_id' => null, 'author' => 'staff', 'body' => mb_substr($body, 0, 2000), 'internal' => false,
            'external_id' => $id && ! ChatMessage::query()->where('external_id', $id)->exists() ? $id : null]);
        $t->forceFill(['last_message_at' => now()])->save();

        return true;
    }

    /** The lead's open conversation, if it has one. */
    public static function threadOf(?int $leadId): ?ChatThread
    {
        return $leadId ? ChatThread::query()->where('kind', 'social')->where('lead_id', $leadId)->latest('last_inbound_at')->first() : null;
    }
}
