<?php

namespace App\Support\Site;

use App\Enums\TripStatus;
use App\Http\Controllers\Api\V1\BookingRequestController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\Api\V1\PriceEstimateController;
use App\Http\Resources\Api\FleetResource;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Lead;
use App\Models\Package;
use App\Models\Trip;
use App\Models\WebsiteChat;
use App\Models\Yacht;
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
        if ($action === 'enquiry' || preg_match('/call me|speak to|contact the team|talk to (the |our )?team|تحدث|اتصل/iu', $message)) {
            return $this->enquiry($in, $slug, $locale, $message);
        }
        if ($this->isGreeting($message)) {
            return ['greet', '', $this->greet($locale), null, null, null, null];
        }
        if (preg_match('/how (do|can) i book|how to book|how (does|do) (a |the )?booking|booking steps|how to rent|كيف أحجز|كيف احجز|خطوات الحجز|كيف يتم الحجز/iu', $message)) {
            return ['guide', '', $this->guide($locale), null, null, null, null];
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
        $covered = $this->covered($message, $slug, $locale);
        if ($covered) {
            return $covered;
        }
        $start = $in['start_at'] ?? null;
        $end = $in['end_at'] ?? null;
        if ((! $start || ! $end) && preg_match_all('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $message, $found) && count($found[0]) >= 2) {
            [$start, $end] = $found[0];
        }
        if ($slug && $start && $end && ! $this->aboutAnotherService($message)) {
            return $this->estimate($slug, $start, $end, $in['drive_mode'] ?? null, $locale);
        }
        if ($slug || $this->isCarQuestion($message) || $this->mentionsPublishedCar($message)) {
            return $this->search($message, $slug && ! $this->aboutAnotherService($message) ? $slug : null, $locale);
        }

        return ['guide', '', $this->menu($locale), null, null, null, null];
    }

    protected function isGreeting(string $message): bool
    {
        return preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening|salam|salaam|assalamu?\s*alaikum|thanks|thank you|مرحبا|مرحباً|السلام عليكم|أهلا|اهلا|شكرا|شكراً)[\s!.?]*$/iu', $message) === 1;
    }

    protected function greet(string $locale): string
    {
        return $locale === 'ar'
            ? 'أهلاً بك في ريد برايم. أستطيع المساعدة في السيارات واليخوت والرحلات والباقات والليموزين والحجز والمستندات والدفع، أو طلب اتصال من الفريق. ماذا تحتاج؟'
            : 'Hello. Welcome to RED PRIME. I can help with published cars, yachts, yacht trips, packages, limousine transfers, a booking, documents, payment, or a call from our team. What do you need?';
    }

    protected function guide(string $locale): string
    {
        return $locale === 'ar'
            ? "يسير الحجز بهذه الخطوات.\n- افتح السيارة وتأكد من السعر المنشور.\n- أرسل الاسم والهاتف والبريد وتاريخ البداية والنهاية.\n- يُحفظ الحجز بانتظار الدفع إلى أن يراجع الفريق المستندات.\n- ارفع المستندات في حسابك، لا في هذه المحادثة.\n- عندما يتوفر الدفع الإلكتروني، الرابط لمجموع الحجز والعربون المسترد منفصل.\n- للحديث مع شخص أولاً، اترك اسمك ورقم هاتفك وسيتواصل معك الفريق. ذلك طلب اتصال وليس حجزاً."
            : "A booking follows these steps.\n- Open the car and check the published price.\n- Send your name, phone, email, and the start and end.\n- The booking is saved waiting for payment until our team has checked your documents.\n- Upload the documents in your account, not in this chat.\n- When online payment is available, the link is the booking total. The refundable deposit is separate.\n- To speak to someone first, leave your name and phone. Our team will call you. That is a call request, not a booking.";
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string}|null */
    protected function covered(string $message, ?string $slug, string $locale): ?array
    {
        $onThisCar = $slug && preg_match('/price|seat|deposit|daily|door|driver|chauffeur|km|سعر|مقعد|عربون|وديعة|سائق/iu', $message);
        if (preg_match('/yacht|يخوت|يخت/iu', $message)) {
            return $this->yachts($locale);
        }
        if (preg_match('/package|باقة|باقات/iu', $message)) {
            return $this->packages($locale);
        }
        if (preg_match('/yacht trip|shared trip|رحلة|رحلات/iu', $message) || (preg_match('/\btrips?\b/iu', $message) && ! preg_match('/road trip/iu', $message))) {
            return $this->trips($locale);
        }
        if (preg_match('/limousine|limo|airport|transfer|مطار|ليموزين|توصيل المطار/iu', $message)) {
            return ['limo', '', $this->limo($locale), null, null, null, null];
        }
        if (preg_match('/who are you|about (you|us|red prime)|من أنتم|عنكم|عن الشركة/iu', $message)) {
            return ['about', '', $this->about($locale), null, null, null, null];
        }
        if (preg_match('/what (do you|can you) (offer|do)|your services|خدمات|ماذا تقدم/iu', $message)) {
            return ['services', '', $this->services($locale), null, null, null, null];
        }
        if (! $onThisCar && preg_match('/document|papers|emirates id|licence|license|مستند|وثائق|رخصة/iu', $message)) {
            return ['guide', '', $this->documents($locale), null, null, null, null];
        }
        if (! $onThisCar && preg_match('/security deposit|the deposit|عربون|وديعة/iu', $message)) {
            return ['guide', '', $this->deposit($locale), null, null, null, null];
        }
        if (! $onThisCar && preg_match('/how (can|do) i pay|payment link|how to pay|كيف أدفع|طريقة الدفع/iu', $message)) {
            return ['guide', '', $this->payment($locale), null, null, null, null];
        }
        if (! $onThisCar && preg_match('/deliver|توصيل/iu', $message) && ! preg_match('/limousine|airport|مطار/iu', $message)) {
            return ['guide', '', $this->delivery($locale), null, null, null, null];
        }
        if (! $onThisCar && preg_match('/chauffeur|with a driver|سائق/iu', $message)) {
            return ['guide', '', $this->chauffeur($locale), null, null, null, null];
        }

        return null;
    }

    protected function aboutAnotherService(string $message): bool
    {
        return preg_match('/yacht|يخوت|يخت|package|باقة|limousine|limo|airport|مطار|ليموزين/iu', $message) === 1;
    }

    protected function isCarQuestion(string $message): bool
    {
        return preg_match('/\bcars?\b|fleet|daily price|سعر يوم|سيارة|سيارات|seats?|مقعد/iu', $message) === 1;
    }

    protected function mentionsPublishedCar(string $message): bool
    {
        $words = collect(preg_split('/\s+/u', mb_strtolower($message)))->filter(fn ($w) => mb_strlen($w) > 2);
        if ($words->isEmpty()) {
            return false;
        }

        return Car::query()->publiclyVisible()->get()->contains(function (Car $car) use ($words) {
            $hay = mb_strtolower($car->name.' '.$car->brand.' '.$car->model);

            return $words->contains(fn ($w) => str_contains($hay, $w));
        });
    }

    protected function menu(string $locale): string
    {
        return $locale === 'ar'
            ? "أستطيع المساعدة في ما هو منشور على الموقع.\n- السيارات\n- اليخوت والتأجير الخاص\n- رحلات اليخت المشتركة\n- الباقات\n- الليموزين وتوصيل المطار\n- خطوات الحجز والمستندات والدفع\n- طلب اتصال من الفريق"
            : "I can help with what we publish.\n- Cars\n- Private yacht charters\n- Shared yacht trips\n- Trips and packages\n- Limousine and airport transfers\n- How a booking, the documents and the payment work\n- A call from our team";
    }

    protected function about(string $locale): string
    {
        return $locale === 'ar'
            ? "ريد برايم للتأجير شركة في دبي لتأجير السيارات الفاخرة واليخوت الخاصة وخدمات السائق.\n- الأسطول منشور على الموقع مع السعر والعربون.\n- عرض السعر والمستندات والدفع والحجز تبقى في حسابك.\n- اترك اسمك ورقم هاتفك إذا أردت أن يتصل بك الفريق."
            : "RED PRIME RENTALS is a Dubai company for luxury and exotic cars, private yachts and chauffeur services.\n- The fleet is published with the price and the deposit.\n- Your quotation, documents, payment and booking stay in your account.\n- Leave your name and phone if you would like the team to call you.";
    }

    protected function services(string $locale): string
    {
        return $locale === 'ar'
            ? "هذه خدماتنا المنشورة.\n- تأجير سيارات بالقيادة الذاتية أو مع سائق\n- تأجير يخوت خاصة مع القبطان والطاقم\n- رحلات يخت مشتركة\n- باقات ورحلات\n- ليموزين وتوصيل المطار\nاسأل عن أي منها، أو اترك اسمك ورقم هاتفك للفريق."
            : "These are the services we publish.\n- Car rental, self-drive or with a chauffeur\n- Private yacht charter with captain and crew\n- Shared yacht trips\n- Trips and packages\n- Limousine and airport transfers\nAsk about any of these, or leave your name and phone for the team.";
    }

    protected function documents(string $locale): string
    {
        return $locale === 'ar'
            ? "المستندات.\n- يخبرك الفريق بالمستندات التي يحتاجها حجزك ويرسل رابطاً آمناً لرفعها.\n- ترفعها في حسابك، لا في هذه المحادثة."
            : "Documents.\n- We tell you which documents your booking needs and send a secure link to upload them.\n- You upload them in your account, not in this chat.";
    }

    protected function deposit(string $locale): string
    {
        return $locale === 'ar'
            ? "العربون المسترد.\n- يختلف حسب السيارة ويظهر في صفحة السيارة.\n- يُحجز عند التسليم وهو منفصل عن مبلغ الحجز."
            : "The refundable security deposit.\n- It depends on the car and is shown on the car page.\n- It is held at handover and is separate from the booking total.";
    }

    protected function payment(string $locale): string
    {
        return $locale === 'ar'
            ? "الدفع.\n- بعد تأكيد الحجز نرسل رابط دفع آمناً.\n- الرابط لمجموع الحجز. العربون المسترد منفصل عنه.\n- إذا لم يكن الدفع الإلكتروني متاحاً، يتواصل الفريق معك."
            : "Payment.\n- Once the booking is confirmed we send a secure payment link.\n- The link is the booking total. The refundable deposit is separate.\n- If online payment is not available, the team contacts you.";
    }

    protected function delivery(string $locale): string
    {
        return $locale === 'ar'
            ? "التوصيل.\n- التوصيل والاستلام متاحان لكثير من السيارات حيث يظهر ذلك في الصفحة.\n- الرسوم تظهر في التقدير عند اختيار التوصيل. لا أضيف رسماً غير منشور."
            : "Delivery.\n- Delivery and collection are offered for many cars, where the car page says so.\n- The fee is added to the estimate when you choose delivery. I do not add a fee that is not published.";
    }

    protected function chauffeur(string $locale): string
    {
        return $locale === 'ar'
            ? "السائق.\n- السيارات التي يظهر فيها «السائق متاح» يمكن حجزها مع سائق باليوم أو بالساعة.\n- سعر السائق يظهر على صفحة السيارة عند اختيار «مع سائق»."
            : "A chauffeur.\n- Cars marked chauffeur available can be booked with a professional chauffeur by the day or by the hour.\n- The chauffeur price is on that car page when you choose with chauffeur.";
    }

    protected function limo(string $locale): string
    {
        return $locale === 'ar'
            ? "الليموزين وتوصيل المطار.\n- تختار السيارة ونقطة الانطلاق والوجهة، والسعر يُحسب على مسافة الطريق ويظهر قبل الحجز.\n- الاستقبال وتتبع الرحلة متاحان عند إعطاء رقم الرحلة.\n- الفريق يؤكد السعر النهائي قبل الدفع.\n- اترك اسمك ورقم هاتفك إذا أردت أن يرتب الفريق الرحلة."
            : "Limousine and airport transfers.\n- Choose the car, the pickup and the destination. The price is worked out on the real road distance and shown before you book.\n- Meet and greet, and flight tracking when you give a flight number, are part of the service.\n- Our team confirms the final price before you pay.\n- Leave your name and phone if you would like the team to arrange the ride.";
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string} */
    protected function yachts(string $locale): array
    {
        $items = Yacht::query()->publiclyVisible()->orderBy('name')->get();

        return $this->catalogue('yacht', $items, $locale, function (Yacht $yacht) use ($locale) {
            $public = (new FleetResource($yacht))->additional(['detail' => true])->resolve(request());
            $bits = [$public['name'] ?? $yacht->name];
            $hourly = $public['prices']['hourly'] ?? null;
            if ($hourly !== null) {
                $bits[] = ($locale === 'ar' ? 'بالساعة AED ' : 'hourly AED ').self::money($hourly);
            }
            if (! empty($public['guests'])) {
                $bits[] = $public['guests'].($locale === 'ar' ? ' ضيوف' : ' guests');
            }
            if (! empty($public['captain_included'])) {
                $bits[] = $locale === 'ar' ? 'القبطان مشمول' : 'captain included';
            }

            return implode(', ', $bits);
        }, $locale === 'ar'
            ? 'هذه يخوت منشورة. التأجير الخاص يأتي مع القبطان والطاقم.'
            : 'These are published yachts. A private charter comes with a captain and crew.');
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string} */
    protected function trips(string $locale): array
    {
        $items = Trip::query()->publiclyVisible()->where('status', TripStatus::Active)->orderBy('name')->get();

        return $this->catalogue('trip', $items, $locale, function (Trip $trip) use ($locale) {
            $bits = [$trip->name];
            if ($trip->price !== null) {
                $bits[] = ($locale === 'ar' ? 'للشخص AED ' : 'per person AED ').self::money($trip->price);
            }

            return implode(', ', $bits);
        }, $locale === 'ar' ? 'هذه رحلات يخت منشورة.' : 'These are published yacht trips.');
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string} */
    protected function packages(string $locale): array
    {
        $items = Package::query()->publiclyVisible()->active()->orderBy('name')->get();

        return $this->catalogue('package', $items, $locale, function (Package $package) use ($locale) {
            $bits = [$package->name];
            $price = $package->unitPrice();
            if ($price !== null) {
                $bits[] = 'AED '.self::money($price);
            }

            return implode(', ', $bits);
        }, $locale === 'ar' ? 'هذه باقات منشورة.' : 'These are published packages.');
    }

    /** @param  \Illuminate\Support\Collection<int, mixed>  $items
     * @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string}
     */
    protected function catalogue(string $tool, $items, string $locale, callable $line, string $intro): array
    {
        if ($items->isEmpty()) {
            $text = $locale === 'ar'
                ? "لا يوجد شيء منشور هنا الآن.\n- اترك اسمك ورقم هاتفك وسيتواصل معك الفريق."
                : "Nothing is published here right now.\n- Leave your name and phone and our team will call you.";

            return [$tool, '', $text, null, null, null, null];
        }
        $facts = $items->take(5)->map(fn ($item) => '- '.$line($item))->implode("\n");
        $hint = $locale === 'ar'
            ? 'اترك اسمك ورقم هاتفك إذا أردت أن يرتب الفريق الطلب.'
            : 'Leave your name and phone if you would like the team to arrange it.';

        return [$tool, $facts, $intro."\n".$facts."\n".$hint, null, null, null, null];
    }

    protected function contact(string $locale): string
    {
        $phone = Site::phone();
        $address = Site::address();
        if (blank($phone) && blank($address)) {
            return $this->handoff($locale);
        }
        $lines = array_filter([
            $phone ? ($locale === 'ar' ? '- يمكنكم الاتصال بنا على '.$phone : '- You can call us on '.$phone) : null,
            $address ? ($locale === 'ar' ? '- مكتبنا في '.$address : '- Our office is at '.$address) : null,
            $locale === 'ar'
                ? '- أو اترك اسمك ورقم هاتفك هنا وسيتواصل معك الفريق.'
                : '- Or leave your name and phone here and our team will call you.',
        ]);
        $intro = $locale === 'ar' ? 'يمكنكم التواصل معنا.' : 'You can reach us.';

        return $intro."\n".implode("\n", $lines);
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
        $lines = $cars->take(5)->map(fn (Car $car) => '- '.$this->carLine($car, $message, $locale));
        $facts = $lines->implode("\n");
        if ($facts === '') {
            return ['search', '', $this->handoff($locale), null, null, null, null];
        }
        $intro = $locale === 'ar' ? 'هذه سيارات منشورة:' : 'These are the published cars:';
        $hint = $locale === 'ar'
            ? 'للخطوات اسأل «كيف أحجز». ولطلب اتصال، اترك اسمك ورقم هاتفك وسيتواصل معك الفريق.'
            : 'Ask how to book for the steps. Leave your name and phone if you would like our team to call you.';

        return ['search', $facts, $intro."\n".$facts."\n".$hint, null, null, null, null];
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
                ? $name." غير متاحة في هذه التواريخ.\n- يستطيع الفريق اقتراح سيارة أخرى إذا تركت اسمك ورقم هاتفك."
                : $name." is not available for these dates.\n- Our team can suggest another published car if you leave your name and phone.";

            return ['estimate', $facts, $text, null, null, null, null];
        }
        $total = $data['price']['total'] ?? null;
        $deposit = $data['security_deposit'] ?? null;
        $facts = trim($name.' total '.($total !== null ? 'AED '.self::money($total) : '').($deposit ? ' deposit AED '.self::money($deposit) : ''));
        if ($total === null) {
            return ['estimate', $facts, $this->handoff($locale), null, null, null, null];
        }
        $lines = [$locale === 'ar'
            ? 'التقدير المنشور لـ '.$name.' هو AED '.self::money($total).'.'
            : 'The published estimate for '.$name.' is AED '.self::money($total).'.'];
        if ($deposit) {
            $lines[] = $locale === 'ar'
                ? '- العربون المسترد AED '.self::money($deposit).' منفصل عن المجموع.'
                : '- The refundable deposit is AED '.self::money($deposit).', separate from the total.';
        }
        $lines[] = $locale === 'ar'
            ? '- هذا ليس حجزاً مؤكداً.'
            : '- This is not a confirmed booking.';
        $lines[] = $locale === 'ar'
            ? '- أرسل الاسم والهاتف والبريد مع هذه التواريخ إذا أردت أن يستلم الفريق الطلب.'
            : '- Send your name, phone and email with these dates if you would like the team to take the request.';
        $text = implode("\n", $lines);

        return ['estimate', $facts, $text, null, null, null, null];
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: ?string, 6: ?string} */
    protected function book(array $in, ?string $slug, string $locale): array
    {
        $missing = collect(['product_slug' => $slug, 'start_at' => $in['start_at'] ?? null, 'end_at' => $in['end_at'] ?? null, 'name' => $in['name'] ?? null, 'phone' => $in['phone'] ?? null, 'email' => $in['email'] ?? null])
            ->filter(fn ($v) => blank($v))->keys();
        if ($missing->isNotEmpty() || ! $slug) {
            $text = $locale === 'ar'
                ? "لإتمام الحجز أحتاج:\n- الاسم والهاتف والبريد وتاريخ البداية والنهاية.\n- يُحفظ الحجز بانتظار الدفع إلى أن يراجعه الفريق."
                : "To complete the booking I need:\n- Your name, phone, email, and the start and end.\n- The booking is saved waiting for payment until our team has checked it.";

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
            ? "تم حفظ الحجز {$reference} وهو بانتظار الدفع.\n- يراجعه الفريق.\n- ارفع المستندات في حسابك."
            : "Your booking {$reference} is saved and waiting for payment.\n- Our team will check it.\n- Upload your documents in your account.";
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
            $text = $locale === 'ar'
                ? "اترك بياناتك هنا. سيتواصل معك الفريق، ولن يُنشأ حجز من هذه الرسالة.\n- الاسم\n- رقم الهاتف"
                : "Leave your details here. Our team will call you. This message does not create a booking.\n- Your name\n- Your phone";

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
            ? "شكراً لك. سيتواصل معك الفريق.\n- المرجع {$ref}"
            : "Thank you. Our team will contact you.\n- Your reference is {$ref}";

        return ['enquiry', (string) $ref, $text, null, $lead?->id, null, $ref];
    }

    protected function handoff(string $locale): string
    {
        return $locale === 'ar'
            ? "سيؤكد الفريق ذلك.\n- اترك اسمك ورقم هاتفك وسنتصل بك."
            : "Our team will confirm that.\n- Leave your name and phone and we will call you.";
    }

    protected static function money(float|int|string $amount): string
    {
        $n = round((float) $amount, 2);

        return number_format($n, abs($n - round($n)) < 0.001 ? 0 : 2);
    }
}
