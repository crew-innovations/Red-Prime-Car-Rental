<?php

namespace App\Support\Chat;

use App\Models\IntegrationEvent;
use Illuminate\Support\Facades\Http;

/**
 * 19 Nov (suggestion 9): messages the CRM sends by itself on WhatsApp (contract to sign, invoice).
 * Outside a customer's 24-hour window WhatsApp only allows approved templates, so each message is a
 * template named in Settings › Integrations › Instagram, Facebook & WhatsApp. Without the WhatsApp
 * number, token or template nothing is sent and the caller falls back to the WhatsApp link.
 */
class WhatsAppNotify
{
    public static function ready(string $template): bool
    {
        $i = Social::integration();

        return $i->isReady() && filled($i->secret('whatsapp_token')) && filled($i->config('whatsapp_phone_number_id')) && filled($i->config('wa_template_'.$template));
    }

    /** Sends a template; true when WhatsApp accepted it. Never throws. */
    public static function send(?string $phone, string $template, array $values): bool
    {
        $to = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($to) < 8 || ! self::ready($template)) {
            return false;
        }
        if (str_starts_with($to, '00')) {
            $to = substr($to, 2);
        } elseif (str_starts_with($to, '05') && strlen($to) === 10) {
            $to = '971'.substr($to, 1); // UAE mobile typed without the country code
        }
        $i = Social::integration();
        try {
            $res = Http::timeout(20)->acceptJson()->withToken($i->secret('whatsapp_token'))
                ->post('https://graph.facebook.com/'.($i->config('graph_version') ?: 'v21.0').'/'.urlencode((string) $i->config('whatsapp_phone_number_id')).'/messages', [
                    'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
                    'template' => [
                        'name' => (string) $i->config('wa_template_'.$template),
                        'language' => ['code' => (string) ($i->config('wa_template_language') ?: 'en')],
                        'components' => [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => mb_substr((string) $v, 0, 900)], array_values($values))]],
                    ],
                ]);
        } catch (\Throwable $e) {
            IntegrationEvent::log(Social::PROVIDER, 'out', 'failed', 'template '.$template, null, [], class_basename($e));

            return false;
        }
        IntegrationEvent::log(Social::PROVIDER, 'out', $res->successful() ? 'ok' : 'failed', 'template '.$template, data_get($res->json(), 'messages.0.id'), [],
            $res->successful() ? null : mb_substr((string) data_get($res->json(), 'error.message', 'HTTP '.$res->status()), 0, 250));

        return $res->successful();
    }
}
