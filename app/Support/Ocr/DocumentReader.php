<?php

namespace App\Support\Ocr;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 18 Nov (meeting item 7): reads uploaded documents and fills the form. Emirates ID, passport,
 * driving licence, visa and trade licence fill the customer; a receipt fills the expense.
 *
 * The reading is done by an outside AI vision service set up under Settings › Integrations ›
 * "Document reader (OCR)" (decision C-5: which service, and agreeing to send ID images to it).
 * Until it is set up, the button says so and nothing is sent anywhere. Staff always check the
 * filled-in fields before saving; what was read is kept on the document for reference.
 */
class DocumentReader
{
    public const PROVIDERS = ['anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI (GPT)'];

    public const CUSTOMER_FIELDS = [
        'name', 'nationality', 'date_of_birth', 'address',
        'id_number', 'id_issued_on', 'id_expires_on', 'passport_number', 'passport_issued_on', 'passport_expires_on',
        'licence_number', 'licence_country', 'licence_place_of_issue', 'licence_issued_on', 'licence_expires_on',
        'trade_licence_number', 'trade_licence_expires_on', 'company_name',
    ];

    public const RECEIPT_FIELDS = ['vendor', 'date', 'amount', 'vat_amount', 'trn', 'description', 'invoice_number'];

    public static function registryEntries(): array
    {
        return [
            'document_reader' => [
                'label' => 'Document reader (OCR)',
                'group' => 'Documents',
                'description' => 'Reads Emirates ID, passport, driving licence, visa, trade licence and receipts and fills the customer or expense form. Staff check the result before saving.',
                'docs' => 'https://docs.claude.com/en/docs/build-with-claude/vision',
                'config' => [
                    'provider' => ['label' => 'Service', 'help' => 'Which AI service reads the documents.', 'default' => 'anthropic', 'options' => self::PROVIDERS],
                    'model' => ['label' => 'Model', 'help' => 'Empty = the default for the service (Anthropic: claude-haiku-5-5, OpenAI: gpt-4o-mini).', 'default' => null],
                ],
                'secrets' => [
                    'api_key' => ['label' => 'API key', 'help' => 'From the service\'s console. Used only by the CRM server.'],
                ],
                'required' => ['api_key'],
                'requirements' => [
                    'An account with the service and an API key with billing switched on.',
                    'RED PRIME agrees that ID and receipt images are sent to that service for reading (its data terms apply).',
                ],
                'tester' => self::class,
            ],
        ];
    }

    public static function integration(): Integration
    {
        return Integration::for('document_reader');
    }

    public static function ready(): bool
    {
        return self::integration()->isReady();
    }

    /**
     * @param  list<array{path: string, mime?: ?string, type?: ?string}>  $files  absolute paths (uploads or stored files)
     * @return array<string, mixed>  only the fields that were found
     */
    public static function readCustomer(array $files): array
    {
        $prompt = 'These are identity documents of one car-rental customer in the UAE (any of: Emirates ID front/back, passport, '
            .'driving licence, international driving permit, visa / entry stamp, company trade licence). Read them and answer with ONE JSON object only, '
            .'no other text, with these keys (null when not shown): name (full name in English, as on the passport if there is one), nationality '
            .'(country name in English), date_of_birth (YYYY-MM-DD), address, id_number (Emirates ID number like 784-YYYY-NNNNNNN-N, or national ID), '
            .'id_expires_on (YYYY-MM-DD), passport_number, passport_expires_on, licence_number, licence_country (issuing country), licence_issued_on, '
            .'licence_expires_on, trade_licence_number, trade_licence_expires_on, company_name, mrz (the machine-readable lines exactly as printed, '
            .'separated by \n, or null), documents (list of {type: emirates_id|passport|driving_licence|international_licence|visa|trade_licence|other, '
            .'number, issued_on, expires_on}). Dates on UAE documents are day/month/year: convert them to YYYY-MM-DD.';

        $out = self::ask($files, $prompt);

        // The MRZ has check digits: where it reads cleanly it wins over the printed text.
        if (! empty($out['mrz']) && ($m = Mrz::parse((string) $out['mrz'])) && $m['valid']) {
            if ($m['type'] === 'passport') {
                $out['passport_number'] = $m['number'] ?? ($out['passport_number'] ?? null);
                $out['passport_expires_on'] = $m['expires_on'] ?? ($out['passport_expires_on'] ?? null);
            } elseif ($m['number'] && str_starts_with($m['number'], '784')) {
                $out['id_number'] = self::emiratesId($m['number']);
                $out['id_expires_on'] = $m['expires_on'] ?? ($out['id_expires_on'] ?? null);
            }
            $out['date_of_birth'] = $m['date_of_birth'] ?? ($out['date_of_birth'] ?? null);
        }
        if (! empty($out['id_number'])) {
            $out['id_number'] = self::emiratesId((string) $out['id_number']);
        }

        return self::clean($out, array_merge(self::CUSTOMER_FIELDS, ['documents', 'mrz']));
    }

    /** @return array<string, mixed> */
    public static function readReceipt(array $files): array
    {
        $prompt = 'This is a receipt or supplier invoice paid by a car-rental company in the UAE. Answer with ONE JSON object only, no other text, '
            .'with these keys (null when not shown): vendor (shop or company name), date (YYYY-MM-DD; UAE dates are day/month/year), amount '
            .'(the total paid as a number, VAT included), vat_amount (number), trn (the vendor\'s 15-digit tax number), invoice_number, '
            .'description (what was bought, in a few words, English).';

        $out = self::ask($files, $prompt);
        foreach (['amount', 'vat_amount'] as $k) {
            if (isset($out[$k])) {
                $out[$k] = is_numeric($n = str_replace([',', 'AED', ' '], '', (string) $out[$k])) ? round((float) $n, 2) : null;
            }
        }

        return self::clean($out, self::RECEIPT_FIELDS);
    }

    /** 784198012345671 → 784-1980-1234567-1 */
    public static function emiratesId(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);

        return strlen($digits) === 15 && str_starts_with($digits, '784')
            ? substr($digits, 0, 3).'-'.substr($digits, 3, 4).'-'.substr($digits, 7, 7).'-'.substr($digits, 14, 1)
            : trim($value);
    }

    /** Connection test for Settings › Integrations: a tiny request that needs no image. */
    public static function test(Integration $i): array
    {
        if (! $i->hasSecret('api_key')) {
            return [false, 'Save the API key first.', null];
        }
        try {
            $text = self::call($i, [['type' => 'text', 'text' => 'Reply with the JSON object {"ok": true} and nothing else.']]);

            return str_contains($text, 'true') ? [true, null, 'The document reader answered'] : [false, 'Unexpected answer: '.mb_substr($text, 0, 120), null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage(), null];
        }
    }

    /* ---------------------------------------------------------------- */

    protected static function ask(array $files, string $prompt): array
    {
        $i = self::integration();
        if (! $i->isReady()) {
            throw new RuntimeException('The document reader is not set up yet (Settings › Integrations › Document reader). Type the details by hand.');
        }
        $parts = [];
        foreach (array_slice($files, 0, 6) as $f) {
            $path = (string) ($f['path'] ?? '');
            if ($path === '' || ! is_file($path) || filesize($path) > 8 * 1024 * 1024) {
                continue;
            }
            $mime = $f['mime'] ?? (mime_content_type($path) ?: 'application/octet-stream');
            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true)) {
                continue;
            }
            $parts[] = ['mime' => $mime, 'data' => base64_encode((string) file_get_contents($path)), 'label' => $f['type'] ?? null];
        }
        if (! $parts) {
            throw new RuntimeException('Upload a photo or PDF of the document first (JPG, PNG, WEBP or PDF, up to 8 MB).');
        }

        $content = [];
        foreach ($parts as $p) {
            if ($p['label']) {
                $content[] = ['type' => 'text', 'text' => 'Uploaded as: '.$p['label']];
            }
            $content[] = ['type' => $p['mime'] === 'application/pdf' ? 'pdf' : 'image', 'mime' => $p['mime'], 'data' => $p['data']];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $text = self::call($i, $content);
        $json = self::json($text);
        if ($json === null) {
            throw new RuntimeException('The document could not be read. Try a clearer photo, or type the details.');
        }

        return $json;
    }

    /** One request to the chosen service; returns its text answer. */
    protected static function call(Integration $i, array $content): string
    {
        $provider = (string) ($i->config['provider'] ?? 'anthropic');
        $model = filled($i->config['model'] ?? null) ? (string) $i->config['model'] : null;
        $key = (string) $i->secret('api_key');

        if ($provider === 'openai') {
            $parts = collect($content)->map(fn ($c) => match ($c['type']) {
                'image' => ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$c['mime'].';base64,'.$c['data']]],
                'pdf' => ['type' => 'file', 'file' => ['filename' => 'document.pdf', 'file_data' => 'data:application/pdf;base64,'.$c['data']]],
                default => ['type' => 'text', 'text' => $c['text']],
            })->values()->all();
            $r = Http::withToken($key)->timeout(60)->acceptJson()->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model ?: 'gpt-4o-mini',
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'user', 'content' => $parts]],
            ]);
            if (! $r->successful()) {
                throw new RuntimeException('OpenAI: '.($r->json('error.message') ?: 'HTTP '.$r->status()));
            }

            return (string) $r->json('choices.0.message.content', '');
        }

        $parts = collect($content)->map(fn ($c) => match ($c['type']) {
            'image' => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $c['mime'], 'data' => $c['data']]],
            'pdf' => ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $c['data']]],
            default => ['type' => 'text', 'text' => $c['text']],
        })->values()->all();
        $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(60)->acceptJson()
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model ?: 'claude-haiku-5-5',
                'max_tokens' => 1500,
                'messages' => [['role' => 'user', 'content' => $parts]],
            ]);
        if (! $r->successful()) {
            throw new RuntimeException('Anthropic: '.($r->json('error.message') ?: 'HTTP '.$r->status()));
        }

        return (string) collect((array) $r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
    }

    /** The first JSON object in a text answer. */
    public static function json(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);

            return is_array($data) ? $data : null;
        }

        return null;
    }

    protected static function clean(array $data, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = $data[$k] ?? null;
            if (is_string($v)) {
                $v = trim($v);
                if ($v === '' || in_array(strtolower($v), ['null', 'n/a', 'unknown', '-'], true)) {
                    continue;
                }
                if ((str_ends_with($k, '_on') || $k === 'date_of_birth' || $k === 'date') && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    continue; // only clean dates go into date fields
                }
                $v = mb_substr($v, 0, 255);
            }
            if ($v !== null && $v !== []) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
