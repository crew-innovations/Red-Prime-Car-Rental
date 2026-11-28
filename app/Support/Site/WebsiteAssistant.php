<?php

namespace App\Support\Site;

use App\Http\Controllers\Api\V1\BookingRequestController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\Api\V1\PriceEstimateController;
use App\Http\Resources\Api\FleetResource;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\WebsiteChat;
use App\Support\Payments\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Public website assistant. Answers only from the published catalogue, the price
 * estimate, Book now, and the enquiry form. It never reads other customers.
 */
class WebsiteAssistant
{
    public const PROVIDER = 'website_assistant';

    public static function registryEntries(): array
    {
        return [self::PROVIDER => [
            'label' => 'Website assistant',
            'group' => 'Website',
            'description' => 'Answers questions on the public website from published cars, and can book or take an enquiry. It does not read customer records.',
            'docs' => 'https://docs.claude.com/en/docs/build-with-claude/vision',
            'config' => [
                'provider' => ['label' => 'Service', 'help' => 'Which AI service writes the reply. The facts still come from the CRM.', 'default' => 'anthropic', 'options' => \App\Support\Ocr\DocumentReader::PROVIDERS],
                'model' => ['label' => 'Model', 'help' => 'Empty = the default (Anthropic: claude-haiku-5-5, OpenAI: gpt-4o-mini).', 'default' => null],
            ],
            'secrets' => [
                'api_key' => ['label' => 'API key', 'help' => 'From the service console. Used only by the CRM server. Not the document reader key.'],
            ],
            'required' => ['api_key'],
            'requirements' => [
                'An account with billing switched on.',
                'The assistant is shown on the website only after Test connection succeeds.',
            ],
            'tester' => self::class,
        ]];
    }

    public static function integration(): Integration
    {
        return Integration::for(self::PROVIDER);
    }

    public static function ready(): bool
    {
        $i = self::integration();

        return $i->exists && $i->isConnected();
    }

    /** @return array{0: bool, 1: ?string, 2: ?string} */
    public static function test(Integration $i): array
    {
        if (! $i->hasSecret('api_key')) {
            return [false, 'Save the API key first.', null];
        }
        try {
            $text = self::complete($i, 'Reply with the word ready and nothing else.');

            return str_contains(strtolower($text), 'ready')
                ? [true, null, 'The website assistant answered']
                : [false, 'Unexpected answer: '.mb_substr($text, 0, 120), null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage(), null];
        }
    }

    /** @param  array<string, mixed>  $in
     * @return array{reply: string, tool: string, pay_url: ?string, reference: ?string}
     */
    public function reply(array $in): array
    {
        $locale = ($in['locale'] ?? 'en') === 'ar' ? 'ar' : 'en';
        $slug = filled($in['product_slug'] ?? null) ? (string) $in['product_slug'] : null;
        $message = trim((string) $in['message']);
        [$tool, $facts, $draft, $bookingId, $leadId, $payUrl, $reference] = $this->run($in, $message, $slug, $locale);
        $reply = $this->polish($draft, $facts, $locale) ?? $draft;

        WebsiteChat::create([
            'visitor_id' => $in['visitor_id'],
            'locale' => $locale,
            'product_slug' => $slug,
            'question' => $message,
            'reply' => $reply,
            'tool' => $tool,
            'booking_id' => $bookingId,
            'lead_id' => $leadId,
        ]);

        return ['reply' => $reply, 'tool' => $tool, 'pay_url' => $payUrl, 'reference' => $reference];
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string} */
    protected function run(array $in, string $message, ?string $slug, string $locale): array
    {
        $action = $in['action'] ?? null;
        if ($action === 'book' || preg_match('/^\s*(book|احجز)\b/iu', $message)) {
            return $this->book($in, $slug, $locale);
        }
        if ($action === 'enquiry' || preg_match('/call me|speak to|تحدث|اتصل/iu', $message)) {
            return $this->enquiry($in, $slug, $locale, $message);
        }
        if (preg_match('/phone number|mobile number|other customer|رقم هاتف|عميل آخر/iu', $message)) {
            return ['handoff', '', $this->handoff($locale), null, null, null, null];
        }
        if (preg_match('/\b(fee|penalty|cancellation charge|رسوم)\b/iu', $message) && ! preg_match('/daily|price|how much|سعر|يوم/iu', $message)) {
            return ['handoff', '', $this->handoff($locale), null, null, null, null];
        }
        $start = $in['start_at'] ?? null;
        $end = $in['end_at'] ?? null;
        if ((! $start || ! $end) && preg_match_all('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $message, $found) && count($found[0]) >= 2) {
            [$start, $end] = $found[0];
        }
        if ($slug && $start && $end) {
            return $this->estimate($slug, $start, $end, $in['drive_mode'] ?? null, $locale);
        }

        return $this->search($message, $slug, $locale);
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string} */
    protected function search(string $message, ?string $slug, string $locale): array
    {
        $cars = Car::query()->publiclyVisible()->orderBy('name')->get();
        if ($slug) {
            $cars = $cars->where('slug', $slug)->values();
        } else {
            $words = collect(preg_split('/\s+/u', mb_strtolower($message)))->filter(fn ($w) => mb_strlen($w) > 2);
            $matched = $cars->filter(function (Car $car) use ($words) {
                $hay = mb_strtolower($car->name.' '.$car->brand.' '.$car->model);

                return $words->contains(fn ($w) => str_contains($hay, $w));
            })->values();
            if ($matched->isNotEmpty()) {
                $cars = $matched;
            }
        }
        $lines = $cars->take(5)->map(function (Car $car) {
            $public = (new FleetResource($car))->additional(['detail' => true])->resolve(request());
            $daily = $public['prices']['daily'] ?? null;

            return $car->name.($daily !== null ? ' — AED '.self::money($daily).' '.__('site.common.per_day', [], 'en') : '');
        });
        $facts = $lines->implode("\n");
        if ($facts === '') {
            return ['search', '', $this->handoff($locale), null, null, null, null];
        }
        $intro = $locale === 'ar' ? 'هذه سيارات منشورة:' : 'These are published cars:';

        return ['search', $facts, $intro."\n".$facts, null, null, null, null];
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string} */
    protected function estimate(string $slug, string $start, string $end, ?string $mode, string $locale): array
    {
        try {
            $res = app(PriceEstimateController::class)(Request::create('/api/v1/price-estimate', 'POST', [
                'product_type' => 'car', 'product_slug' => $slug, 'start_at' => $start, 'end_at' => $end, 'drive_mode' => $mode,
            ]));
        } catch (ValidationException $e) {
            return ['estimate', '', $this->handoff($locale), null, null, null, null];
        }
        $data = $res->getData(true)['data'] ?? [];
        $name = $data['product']['name'] ?? $slug;
        if (($data['available'] ?? null) === false) {
            $facts = $name.' not available';
            $text = $locale === 'ar'
                ? $name.' غير متاحة في هذه التواريخ.'
                : $name.' is not available for these dates.';

            return ['estimate', $facts, $text, null, null, null, null];
        }
        $total = $data['price']['total'] ?? null;
        $deposit = $data['security_deposit'] ?? null;
        $facts = trim($name.' total '.($total !== null ? 'AED '.self::money($total) : '').($deposit ? ' deposit AED '.self::money($deposit) : ''));
        if ($total === null) {
            return ['estimate', $facts, $this->handoff($locale), null, null, null, null];
        }
        $text = $locale === 'ar'
            ? 'تقدير '.$name.' هو AED '.self::money($total).'.'.($deposit ? ' العربون المسترد AED '.self::money($deposit).' منفصل عن المجموع.' : '').' لم يتم الحجز بعد. يمكن طلب هذه التواريخ.'
            : 'The estimate for '.$name.' is AED '.self::money($total).'.'.($deposit ? ' The refundable deposit is AED '.self::money($deposit).', separate from the total.' : '').' Nothing is booked yet. These dates can be requested.';

        return ['estimate', $facts, $text, null, null, null, null];
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string} */
    protected function book(array $in, ?string $slug, string $locale): array
    {
        $missing = collect(['product_slug' => $slug, 'start_at' => $in['start_at'] ?? null, 'end_at' => $in['end_at'] ?? null, 'name' => $in['name'] ?? null, 'phone' => $in['phone'] ?? null, 'email' => $in['email'] ?? null])
            ->filter(fn ($v) => blank($v))->keys();
        if ($missing->isNotEmpty() || ! $slug) {
            $text = $locale === 'ar'
                ? 'للحجز أحتاج الاسم والهاتف والبريد وتاريخ البداية والنهاية.'
                : 'To book I need your name, phone, email, and the start and end.';

            return ['book', '', $text, null, null, null, null];
        }
        $key = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', 'chat'.$in['visitor_id'].$slug.$in['start_at'].$in['end_at']), 0, 64);
        $payload = [
            'product_type' => 'car', 'product_slug' => $slug, 'request_type' => 'booking',
            'start_at' => $in['start_at'], 'end_at' => $in['end_at'],
            'drive_mode' => $in['drive_mode'] ?? 'self_drive',
            'name' => $in['name'], 'phone' => $in['phone'], 'email' => $in['email'],
            'idempotency_key' => $key, 'pay_now' => (bool) ($in['pay_now'] ?? false),
            'message' => 'Booked from the website assistant.',
        ];
        try {
            $res = app(BookingRequestController::class)->store(Request::create('/api/v1/booking-requests', 'POST', $payload));
        } catch (ValidationException $e) {
            return ['book', '', collect($e->errors())->flatten()->first() ?: $this->handoff($locale), null, null, null, null];
        }
        $data = $res->getData(true)['data'] ?? [];
        $reference = $data['reference'] ?? null;
        $booking = $reference ? Booking::query()->where('reference', $reference)->first() : null;
        $payUrl = (! empty($data['pay_url']) && Payments::connected() !== []) ? $data['pay_url'] : null;
        $facts = trim($reference.' '.($data['total'] ?? '').($payUrl ? ' '.$payUrl : ''));
        $text = $locale === 'ar'
            ? 'تم حفظ الحجز '.$reference.' وهو بانتظار الدفع.'
            : 'Your booking '.$reference.' is saved and waiting for payment.';
        if ($payUrl && ! empty($in['pay_now'])) {
            $text .= $locale === 'ar' ? ' ادفع من هنا: '.$payUrl : ' Pay here: '.$payUrl;
        } elseif ($payUrl) {
            $text .= $locale === 'ar' ? ' رابط الدفع في بريدك.' : ' The payment link is in your email.';
        }

        return ['book', $facts, $text, $booking?->id, $booking?->lead_id, (! empty($in['pay_now']) ? $payUrl : null), $reference];
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string} */
    protected function enquiry(array $in, ?string $slug, string $locale, string $message): array
    {
        if (blank($in['name'] ?? null) || blank($in['phone'] ?? null)) {
            $text = $locale === 'ar' ? 'اترك اسمك ورقم هاتفك وسيتصل بك الفريق.' : 'Leave your name and phone and the team will call you.';

            return ['enquiry', '', $text, null, null, null, null];
        }
        $res = app(EnquiryController::class)->store(Request::create('/api/v1/enquiries', 'POST', [
            'service' => 'car', 'name' => $in['name'], 'phone' => $in['phone'], 'email' => $in['email'] ?? null,
            'message' => trim($message.($slug ? "\nCar: ".$slug : '')), 'kind' => 'callback', 'page' => 'website-assistant',
        ]));
        $ref = $res->getData(true)['data']['reference'] ?? null;
        $lead = null;
        if (is_string($ref) && preg_match('/LD-(\d+)/', $ref, $m)) {
            $lead = Lead::query()->find((int) $m[1]);
        }
        $text = $locale === 'ar'
            ? 'شكراً لك. سيتواصل معك الفريق. المرجع '.$ref.'.'
            : 'Thank you. Our team will contact you. Your reference is '.$ref.'.';

        return ['enquiry', (string) $ref, $text, null, $lead?->id, null, $ref];
    }

    protected function handoff(string $locale): string
    {
        return $locale === 'ar'
            ? 'سيؤكد الفريق ذلك. اترك اسمك ورقم هاتفك وسنتصل بك.'
            : 'Our team will confirm that. Leave your name and phone and we will call you.';
    }

    protected function polish(string $draft, string $facts, string $locale): ?string
    {
        $i = self::integration();
        if (! $i->exists || ! $i->isConnected()) {
            return null;
        }
        try {
            $text = trim(self::complete($i, "Rewrite the reply in ".($locale === 'ar' ? 'Arabic' : 'English').". Use only these facts. Do not add prices, fees, phone numbers, or customers.\nFACTS:\n".$facts."\nREPLY:\n".$draft));
        } catch (\Throwable) {
            return null;
        }
        if ($text === '' || ! $this->safe($text, $facts."\n".$draft)) {
            return null;
        }

        return $text;
    }

    /** A polished reply may not introduce an amount or a phone that was not in the facts. */
    protected function safe(string $text, string $facts): bool
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $found);
        foreach ($found[0] as $n) {
            $plain = str_replace(',', '', $n);
            if ((float) $plain >= 10 && ! str_contains(str_replace(',', '', $facts), $plain)) {
                return false;
            }
        }
        preg_match_all('/\+?\d[\d\s\-()]{7,}/', $text, $phones);
        foreach ($phones[0] as $phone) {
            $digits = preg_replace('/\D+/', '', $phone);
            if (strlen($digits) >= 8 && ! str_contains(preg_replace('/\D+/', '', $facts), $digits)) {
                return false;
            }
        }

        return true;
    }

    protected static function complete(Integration $i, string $prompt): string
    {
        $provider = (string) ($i->config('provider') ?: 'anthropic');
        $model = filled($i->config('model')) ? (string) $i->config('model') : null;
        $key = (string) $i->secret('api_key');
        if ($provider === 'openai') {
            $r = Http::withToken($key)->timeout(20)->acceptJson()->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model ?: 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
            if (! $r->successful()) {
                throw new \RuntimeException('OpenAI: '.($r->json('error.message') ?: 'HTTP '.$r->status()));
            }

            return (string) $r->json('choices.0.message.content', '');
        }
        $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(20)->acceptJson()
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model ?: 'claude-haiku-5-5',
                'max_tokens' => 600,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        if (! $r->successful()) {
            throw new \RuntimeException('Anthropic: '.($r->json('error.message') ?: 'HTTP '.$r->status()));
        }

        return (string) collect((array) $r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
    }

    protected static function money(float|int|string $amount): string
    {
        $n = round((float) $amount, 2);

        return number_format($n, abs($n - round($n)) < 0.001 ? 0 : 2);
    }
}
