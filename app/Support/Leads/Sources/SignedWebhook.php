<?php

namespace App\Support\Leads\Sources;

use App\Models\Integration;
use App\Support\Leads\LeadIntake;
use App\Support\Payments\InvalidWebhook;
use Illuminate\Http\Request;

/**
 * TikTok and Snapchat lead forms sent to the CRM as a signed webhook
 * (HMAC-SHA256 of the raw body with the shared secret, in a header you choose).
 * The payload is read flexibly: a lead id, the form / ad / campaign ids and names,
 * and the answers as a list of {name, value} or as plain fields.
 * The exact format must be confirmed with one real test lead from the platform
 * before switching on (status stays "not tested" until then).
 */
abstract class SignedWebhook extends Source
{
    abstract protected static function label(): string;

    abstract protected static function defaultHeader(): string;

    public function leads(Request $request): array
    {
        $raw = $request->getContent();
        $header = (string) ($this->integration->config('signature_header') ?: static::defaultHeader());
        if (! self::hmacOk($raw, $request->header($header), $this->integration->secret('webhook_secret'))) {
            throw new InvalidWebhook($header.' does not match');
        }
        $body = json_decode($raw, true);
        if (! is_array($body)) {
            throw new InvalidWebhook('Unreadable payload');
        }
        $items = isset($body['leads']) && is_array($body['leads']) ? $body['leads'] : (isset($body['data']) && array_is_list((array) $body['data']) ? $body['data'] : [$body['data'] ?? $body]);

        return collect($items)->filter(fn ($l) => is_array($l))->map(fn (array $l) => $this->one($l))->values()->all();
    }

    protected function one(array $l): array
    {
        $id = (string) ($l['lead_id'] ?? $l['id'] ?? '');
        if ($id === '') {
            throw new InvalidWebhook('Lead without an id');
        }
        $list = $l['fields'] ?? $l['answers'] ?? $l['field_data'] ?? $l['lead_data'] ?? $l['user_info'] ?? null;
        $answers = [];
        if (is_array($list) && array_is_list($list)) {
            foreach ($list as $f) {
                $answers[(string) ($f['name'] ?? $f['field_name'] ?? $f['key'] ?? $f['label'] ?? '')] = $f['value'] ?? $f['field_value'] ?? $f['values'] ?? '';
            }
        } elseif (is_array($list)) {
            $answers = $list;
        } else {
            $answers = collect($l)->only(['name', 'full_name', 'first_name', 'last_name', 'phone', 'phone_number', 'email'])->all();
        }
        unset($answers['']);

        return LeadIntake::fromAnswers($answers) + $this->defaults() + [
            'channel' => static::key(), 'source_slug' => static::key(), 'source_name' => static::label(),
            'external_id' => $id, 'event_id' => 'lead-'.$id,
            'platform' => array_filter([
                'advertiser_id' => $l['advertiser_id'] ?? $l['ad_account_id'] ?? null,
                'form_id' => $l['form_id'] ?? $l['page_id'] ?? null, 'form_name' => $l['form_name'] ?? $l['page_name'] ?? null,
                'campaign_id' => $l['campaign_id'] ?? null, 'campaign_name' => $l['campaign_name'] ?? null,
                'adset_id' => $l['adgroup_id'] ?? $l['ad_squad_id'] ?? $l['adset_id'] ?? null, 'adset_name' => $l['adgroup_name'] ?? $l['ad_squad_name'] ?? $l['adset_name'] ?? null,
                'ad_id' => $l['ad_id'] ?? null, 'ad_name' => $l['ad_name'] ?? null,
                'created_time' => $l['create_time'] ?? $l['created_time'] ?? $l['created_at'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'utm_source' => static::key(), 'utm_medium' => 'lead_form',
        ];
    }

    public static function test(Integration $i): array
    {
        if (! $i->secret('webhook_secret')) {
            return [false, 'Shared secret not saved'];
        }
        $last = \App\Models\IntegrationEvent::query()->where('provider', static::key())->where('direction', 'in')->where('status', 'ok')->latest('id')->first();

        return $last
            ? [true, null, static::label().' · last lead received '.$last->created_at->format('d M Y H:i')]
            : [false, 'No signed test lead received yet. Send a test lead from '.static::label().' to the webhook address, then test again.'];
    }
}
