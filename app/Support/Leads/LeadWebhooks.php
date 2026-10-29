<?php

namespace App\Support\Leads;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Support\Payments\GatewayError;
use App\Support\Payments\InvalidWebhook;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/** Lead webhooks from ad platforms: signature checked, each lead handled once, then LeadIntake. */
class LeadWebhooks
{
    public const SOURCES = [
        'meta' => Sources\Meta::class,
        'tiktok' => Sources\TikTok::class,
        'snapchat' => Sources\Snapchat::class,
    ];

    public static function source(string $key): ?Sources\Source
    {
        $class = self::SOURCES[$key] ?? null;
        $i = $class ? Integration::query()->where('provider', $key)->first() : null;

        return $i && $i->isReady() ? new $class($i) : null;
    }

    /** GET: the platform checks the address (Meta "hub.challenge"). */
    public static function challenge(string $key, Request $request): array
    {
        $source = self::source($key);
        $answer = $source?->challenge($request);
        IntegrationEvent::log($key, 'in', $answer !== null ? 'ok' : 'rejected', 'verify address', null, [], $answer !== null ? null : 'Verify token did not match');

        return $answer !== null ? [$answer, 200] : ['', 403];
    }

    /** POST: returns [summary, http status]. */
    public static function receive(string $key, Request $request): array
    {
        $source = self::source($key);
        if (! $source) {
            IntegrationEvent::log($key, 'in', 'rejected', 'lead webhook', null, [], 'Not set up or switched off');

            return [['received' => false], 404];
        }
        try {
            $leads = $source->leads($request);
        } catch (InvalidWebhook $e) {
            IntegrationEvent::log($key, 'in', 'rejected', 'lead webhook', null, ['ip' => $request->ip()], $e->getMessage());

            return [['received' => false], 400];
        } catch (GatewayError $e) {
            IntegrationEvent::log($key, 'in', 'failed', 'lead webhook', null, [], $e->getMessage());

            return [['received' => false], 503]; // the platform retries
        }

        $done = [];
        foreach ($leads as $in) {
            $eventId = mb_substr((string) $in['event_id'], 0, 191);
            try {
                $event = IntegrationEvent::log($key, 'in', 'pending', 'lead', $eventId, ['form' => data_get($in, 'platform.form_id')]);
            } catch (UniqueConstraintViolationException) {
                $event = IntegrationEvent::query()->where('provider', $key)->where('external_id', $eventId)->first();
                $event?->increment('attempts');
                if ($event && $event->status === 'ok') {
                    $done[] = 'duplicate';

                    continue;
                }
            }
            try {
                [$lead, $what] = LeadIntake::receive($in);
                $event?->forceFill(['status' => 'ok', 'error' => null, 'summary' => ['lead' => $lead->id, 'result' => $what, 'form' => data_get($in, 'platform.form_id')]])->save();
                $done[] = $what;
            } catch (\Throwable $e) {
                report($e);
                $event?->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

                return [['received' => false], 500];
            }
        }

        return [['received' => true, 'leads' => $done], 200];
    }

    public static function registryEntries(): array
    {
        $service = ['default_service' => ['label' => 'Service for these leads', 'help' => 'Optional. Leave empty if the form asks it.', 'options' => collect(\App\Enums\ServiceType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])->all()]];

        return [
            'meta' => [
                'label' => 'Facebook & Instagram Lead Ads', 'group' => 'Lead sources', 'tester' => Sources\Meta::class, 'webhook' => '/api/webhooks/leads/meta',
                'description' => 'New leads from Meta lead forms arrive in Leads within seconds, with the campaign, ad and form they came from.',
                'docs' => 'https://developers.facebook.com/docs/marketing-api/guides/lead-ads',
                'config' => $service + [
                    'page_id' => ['label' => 'Facebook Page ID', 'help' => 'The Page the lead forms belong to.'],
                    'graph_version' => ['label' => 'Graph API version', 'help' => 'e.g. v21.0', 'default' => 'v21.0'],
                ],
                'secrets' => [
                    'app_secret' => ['label' => 'App secret', 'help' => 'Meta app › App settings › Basic. Used to check every webhook.'],
                    'page_access_token' => ['label' => 'Page access token (long-lived)', 'help' => 'Needs leads_retrieval, pages_manage_metadata, pages_show_list, pages_read_engagement.'],
                    'verify_token' => ['label' => 'Verify token', 'help' => 'Any long random text; type the same in the Meta webhook settings.'],
                ],
                'required' => ['app_secret', 'page_access_token', 'verify_token'],
                'requirements' => [
                    'A Meta app (Business type) with Webhooks and the leads_retrieval permission approved by Meta (App Review).',
                    'Webhooks › Page › subscribe to "leadgen", callback = the CRM address below, verify token as above.',
                    'The app subscribed to the Page (Page subscribed_apps), and the CRM given access in Page settings › Leads access.',
                ],
            ],
            'tiktok' => [
                'label' => 'TikTok Lead Generation', 'group' => 'Lead sources', 'tester' => Sources\TikTok::class, 'webhook' => '/api/webhooks/leads/tiktok',
                'description' => 'TikTok Instant Form leads sent to the CRM as a signed webhook.',
                'docs' => 'https://business-api.tiktok.com/portal/docs',
                'config' => $service + ['signature_header' => ['label' => 'Signature header', 'help' => 'Header that carries the HMAC-SHA256 of the body.', 'default' => 'X-TikTok-Signature']],
                'secrets' => ['webhook_secret' => ['label' => 'Shared secret', 'help' => 'Used to check the signature of every lead.']],
                'required' => ['webhook_secret'],
                'requirements' => [
                    'A TikTok For Business account with Lead Generation and API / webhook access.',
                    'The webhook pointed to the CRM address below, signing with the shared secret.',
                    'One test lead sent and checked before switching on: the payload format must be confirmed with a real lead.',
                ],
            ],
            'snapchat' => [
                'label' => 'Snapchat Lead Generation', 'group' => 'Lead sources', 'tester' => Sources\Snapchat::class, 'webhook' => '/api/webhooks/leads/snapchat',
                'description' => 'Snapchat lead form submissions sent to the CRM as a signed webhook.',
                'docs' => 'https://marketingapi.snapchat.com/docs/',
                'config' => $service + ['signature_header' => ['label' => 'Signature header', 'help' => 'Header that carries the HMAC-SHA256 of the body.', 'default' => 'X-Snap-Signature']],
                'secrets' => ['webhook_secret' => ['label' => 'Shared secret', 'help' => 'Used to check the signature of every lead.']],
                'required' => ['webhook_secret'],
                'requirements' => [
                    'A Snapchat Ads account with Lead Generation and Marketing API access.',
                    'The webhook pointed to the CRM address below, signing with the shared secret.',
                    'One test lead sent and checked before switching on: the payload format must be confirmed with a real lead.',
                ],
            ],
        ];
    }
}
