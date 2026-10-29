<?php

namespace App\Support\Leads\Sources;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Support\Leads\LeadIntake;
use App\Support\Payments\InvalidWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Facebook / Instagram Lead Ads (Meta Graph API). Docs:
 * https://developers.facebook.com/docs/marketing-api/guides/lead-ads/retrieving
 * Webhook "leadgen" on the Page, signed with the App Secret (X-Hub-Signature-256).
 * The webhook only carries the lead id: the answers are read with the Page token.
 */
class Meta extends Source
{
    public static function key(): string
    {
        return 'meta';
    }

    protected function graph(): string
    {
        return 'https://graph.facebook.com/'.($this->integration->config('graph_version') ?: 'v21.0');
    }

    public function challenge(Request $request): ?string
    {
        $token = $this->integration->secret('verify_token');
        if ($request->query('hub_mode') === 'subscribe' && $token && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return (string) $request->query('hub_challenge');
        }

        return null;
    }

    public function leads(Request $request): array
    {
        $raw = $request->getContent();
        if (! self::hmacOk($raw, $request->header('X-Hub-Signature-256'), $this->integration->secret('app_secret'))) {
            throw new InvalidWebhook('X-Hub-Signature-256 does not match');
        }
        $body = json_decode($raw, true);
        if (! is_array($body) || ($body['object'] ?? null) !== 'page') {
            throw new InvalidWebhook('Not a Page webhook');
        }

        $out = [];
        foreach ((array) ($body['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'leadgen' || empty($change['value']['leadgen_id'])) {
                    continue;
                }
                $id = (string) $change['value']['leadgen_id'];
                // Meta resends webhooks: a lead already saved is not read again.
                if (IntegrationEvent::query()->where('provider', 'meta')->where('external_id', 'leadgen-'.$id)->where('status', 'ok')->exists()) {
                    $out[] = ['event_id' => 'leadgen-'.$id];

                    continue;
                }
                $out[] = $this->fetch($id, (array) $change['value']);
            }
        }

        return $out;
    }

    /** Reads one lead from the Graph API. */
    protected function fetch(string $id, array $hint): array
    {
        $res = Http::timeout(20)->acceptJson()->get($this->graph().'/'.urlencode($id), [
            'access_token' => $this->integration->secret('page_access_token'),
            'fields' => 'created_time,field_data,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,platform,is_organic',
        ]);
        if ($res->failed()) {
            IntegrationEvent::log('meta', 'out', 'failed', 'Read lead', null, ['lead' => $id, 'http' => $res->status()], (string) data_get($res->json(), 'error.message', 'HTTP '.$res->status()));
            throw new \App\Support\Payments\GatewayError('Meta: could not read lead '.$id.' · '.data_get($res->json(), 'error.message', 'HTTP '.$res->status()));
        }
        $l = (array) $res->json();
        $answers = collect((array) ($l['field_data'] ?? []))->mapWithKeys(fn ($f) => [(string) ($f['name'] ?? '') => (array) ($f['values'] ?? [])])->all();

        return LeadIntake::fromAnswers($answers) + $this->defaults() + [
            'channel' => 'meta', 'source_slug' => 'meta', 'source_name' => 'Facebook / Instagram',
            'external_id' => $id, 'event_id' => 'leadgen-'.$id,
            'platform' => array_filter([
                'platform' => $l['platform'] ?? null, 'page_id' => $hint['page_id'] ?? null, 'form_id' => $l['form_id'] ?? ($hint['form_id'] ?? null),
                'campaign_id' => $l['campaign_id'] ?? null, 'campaign_name' => $l['campaign_name'] ?? null,
                'adset_id' => $l['adset_id'] ?? null, 'adset_name' => $l['adset_name'] ?? null,
                'ad_id' => $l['ad_id'] ?? ($hint['ad_id'] ?? null), 'ad_name' => $l['ad_name'] ?? null,
                'organic' => isset($l['is_organic']) ? (bool) $l['is_organic'] : null, 'created_time' => $l['created_time'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'utm_source' => $l['platform'] ?? 'facebook', 'utm_medium' => 'lead_ad',
        ];
    }

    public static function test(Integration $i): array
    {
        foreach (['app_secret' => 'App secret', 'page_access_token' => 'Page access token', 'verify_token' => 'Verify token'] as $k => $label) {
            if (! $i->secret($k)) {
                return [false, $label.' not saved'];
            }
        }
        try {
            $res = Http::timeout(15)->acceptJson()->get('https://graph.facebook.com/'.($i->config('graph_version') ?: 'v21.0').'/me', ['access_token' => $i->secret('page_access_token'), 'fields' => 'id,name']);
        } catch (\Throwable $e) {
            return [false, 'Could not reach Meta: '.class_basename($e)];
        }
        if ($res->failed()) {
            return [false, (string) data_get($res->json(), 'error.message', 'HTTP '.$res->status())];
        }
        if (filled($i->config('page_id')) && (string) data_get($res->json(), 'id') !== (string) $i->config('page_id')) {
            return [false, 'The token belongs to "'.data_get($res->json(), 'name').'", not the Page ID you set.'];
        }

        return [true, null, 'Page: '.data_get($res->json(), 'name').' ('.data_get($res->json(), 'id').')'];
    }
}
