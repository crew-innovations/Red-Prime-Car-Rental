<?php

namespace App\Support\Site;

use App\Http\Controllers\Api\V1\BookingRequestController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\Api\V1\PriceEstimateController;
use App\Http\Resources\Api\FleetResource;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Lead;
use App\Models\WebsiteChat;
use App\Support\Payments\Payments;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Public website chat. Every sentence is written here. Car facts come from the
 * published catalogue. There is no outside AI service and no API key.
 */
class WebsiteAssistant
{
    public static function ready(): bool
    {
        return true;
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

        WebsiteChat::create([
            'visitor_id' => $in['visitor_id'],
            'locale' => $locale,
            'product_slug' => $slug,
            'question' => $message,
            'reply' => $draft,
            'tool' => $tool,
            'booking_id' => $bookingId,
            'lead_id' => $leadId,
        ]);

        return ['reply' => $draft, 'tool' => $tool, 'pay_url' => $payUrl, 'reference' => $reference];
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
        if ($this->isGreeting($message)) {
            return ['greet', '', $this->greet($locale), null, null, null, null];
        }
        if (preg_match('/how (do|can) i book|how to book|كيف أحجز|كيف احجز/iu', $message)) {
            $text = $locale === 'ar'
                ? 'للحجز افتح السيارة وأرسل الاسم والهاتف والبريد وتاريخ البداية والنهاية. أحفظ الحجز بانتظار الدفع.'
                : 'To book, open a car and send your name, phone, email, and the start and end. I save the booking waiting for payment.';

            return ['guide', '', $text, null, null, null, null];
        }
        if (preg_match('/other customer|customer\'s phone|رقم العميل|عميل آخر/iu', $message)) {
            return ['handoff', '', $this->handoff($locale), null, null, null, null];
        }
        if (preg_match('/where are you|your address|your phone|phone number|office number|whatsapp|العنوان|وين|رقمكم|رقم الهاتف/iu', $message)) {
            return ['contact', '', $this->contact($locale), null, null, null, null];
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

    protected function isGreeting(string $message): bool
    {
        return preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening|salam|salaam|assalamu?\s*alaikum|thanks|thank you|مرحبا|مرحباً|السلام عليكم|أهلا|اهلا|شكرا|شكراً)[\s!.?]*$/iu', $message) === 1;
    }

    protected function greet(string $locale): string
    {
        return $locale === 'ar'
            ? 'أهلاً بك في ريد برايم. أستطيع المساعدة في السيارات المنشورة، والسعر لتواريخك، والحجز، أو أن يتصل بك الفريق. ماذا تريد؟'
            : 'Hello. I can help with our published cars, a price for your dates, a booking, or a call from the team. What would you like?';
    }

    protected function contact(string $locale): string
    {
        $phone = Site::phone();
        $address = Site::address();
        if (blank($phone) && blank($address)) {
            return $this->handoff($locale);
        }
        $parts = array_filter([
            $phone ? ($locale === 'ar' ? 'الهاتف '.$phone : 'Phone '.$phone) : null,
            $address ? ($locale === 'ar' ? 'العنوان '.$address : 'Address '.$address) : null,
        ]);

        return implode('. ', $parts).'.';
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
        $lines = $cars->take(5)->map(fn (Car $car) => $this->carLine($car, $message, $locale));
        $facts = $lines->implode("\n");
        if ($facts === '') {
            return ['search', '', $this->handoff($locale), null, null, null, null];
        }
        $intro = $locale === 'ar' ? 'هذه سيارات منشورة:' : 'These are published cars:';

        return ['search', $facts, $intro."\n".$facts, null, null, null, null];
    }

    /** One published car, using only the fields the website already shows. */
    protected function carLine(Car $car, string $message, string $locale): string
    {
        $public = (new FleetResource($car))->additional(['detail' => true])->resolve(request());
        $prices = $public['prices'] ?? [];
        $rules = $public['rules'] ?? [];
        $ask = mb_strtolower($message);
        $wants = fn (string ...$words): bool => collect($words)->contains(fn (string $w) => str_contains($ask, $w));
        $bits = [$public['name'] ?? $car->name];
        $daily = $prices['daily'] ?? null;
        if ($daily !== null && ($wants('price', 'how much', 'cost', 'daily', 'سعر', 'يوم') || ! $wants('seat', 'door', 'deposit', 'gear', 'transmission', 'fuel', 'driver', 'مقعد', 'عربون', 'وديعة'))) {
            $bits[] = ($locale === 'ar' ? 'اليومي AED ' : 'daily AED ').self::money($daily);
        }
        if (isset($prices['weekly']) && $wants('week', 'أسبوع')) {
            $bits[] = ($locale === 'ar' ? 'الأسبوعي AED ' : 'weekly AED ').self::money($prices['weekly']);
        }
        if (isset($prices['monthly']) && $wants('month', 'شهر')) {
            $bits[] = ($locale === 'ar' ? 'الشهري AED ' : 'monthly AED ').self::money($prices['monthly']);
        }
        if (isset($public['deposit']) && $wants('deposit', 'عربون', 'وديعة')) {
            $bits[] = ($locale === 'ar' ? 'العربون المسترد AED ' : 'refundable deposit AED ').self::money($public['deposit']);
        }
        if (isset($public['seats']) && $wants('seat', 'مقعد', 'people', 'passenger')) {
            $bits[] = $public['seats'].($locale === 'ar' ? ' مقاعد' : ' seats');
        }
        if (isset($public['doors']) && $wants('door', 'باب')) {
            $bits[] = $public['doors'].($locale === 'ar' ? ' أبواب' : ' doors');
        }
        if (! empty($public['transmission']) && $wants('gear', 'transmission', 'automatic', 'manual', 'قير')) {
            $bits[] = (string) $public['transmission'];
        }
        if (! empty($public['fuel']) && $wants('fuel', 'petrol', 'diesel', 'وقود')) {
            $bits[] = (string) $public['fuel'];
        }
        if (array_key_exists('with_driver', $public) && $wants('driver', 'chauffeur', 'سائق')) {
            $bits[] = ! empty($public['with_driver'])
                ? ($locale === 'ar' ? 'السائق متاح' : 'a driver is available')
                : ($locale === 'ar' ? 'بدون سائق فقط' : 'self-drive only');
        }
        if (isset($rules['daily_km_limit']) && $wants('km', 'kilomet', 'كيلو')) {
            $bits[] = $rules['daily_km_limit'].($locale === 'ar' ? ' كم في اليوم' : ' km a day');
        }

        return implode(', ', $bits);
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

    protected static function money(float|int|string $amount): string
    {
        $n = round((float) $amount, 2);

        return number_format($n, abs($n - round($n)) < 0.001 ? 0 : 2);
    }
}
