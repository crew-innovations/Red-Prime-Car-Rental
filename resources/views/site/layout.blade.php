@php
    use App\Support\Site\Site;
    $locale = Site::locale();
    $rtl = Site::rtl();
    $title = filled($seo['title'] ?? null) ? $seo['title'].' | '.Site::company() : Site::company().' | '.__('site.seo.home_title');
    $desc = \Illuminate\Support\Str::limit((string) ($seo['description'] ?? __('site.seo.default_description')), 160);
    $routeName = preg_replace('/^site\.(ar\.)?/', '', (string) Route::currentRouteName()) ?: 'home';
    $params = Route::current()?->parameters() ?? [];
    $canonical = Site::absolute(Site::route($routeName, $params));
    $wa = Site::whatsapp(__('site.wa.general'));
    $sections = Site::sections();
    $headerSolid = $headerSolid ?? true;
    $navTypes = $sections['cars'] ? \App\Support\Site\Catalog::types() : [];
    $navBrands = $sections['cars'] ? \App\Support\Site\Catalog::brands() : [];
    $navServices = Site::services();
    $rpConfig = [
        'lang' => $locale,
        'api' => url('/api/v1'),
        'whatsapp' => Site::whatsapp(),
        'aed' => __('site.aed'),
        't' => [
            'loading' => __('site.common.loading'),
            'pick_dates' => __('site.quote.pick_dates'),
            'available' => __('site.quote.available'),
            'unavailable' => __('site.quote.unavailable'),
            'on_request' => __('site.quote.on_request'),
            'no_price' => __('site.quote.no_price'),
            'note' => __('site.quote.note'),
            'subtotal' => __('site.quote.subtotal'),
            'vat' => __('site.quote.vat', ['rate' => ':rate']),
            'vat_included' => __('site.quote.vat_included', ['rate' => ':rate']),
            'total' => __('site.quote.total'),
            'deposit' => __('site.quote.deposit'),
            'sending' => __('site.quote.sending'),
            'send' => __('site.quote.send'),
            'error' => __('site.quote.error'),
            'too_many' => __('site.quote.too_many'),
            'distance' => __('site.limo.distance'),
            'time_est' => __('site.limo.time_est'),
            'minutes' => __('site.common.minutes', ['n' => ':n']),
            'km' => __('site.car.km', ['n' => ':n']),
            'per_day' => __('site.common.per_day'),
            'theme_dark' => __('site.nav.theme_dark'),
            'theme_light' => __('site.nav.theme_light'),
            'done' => __('site.quickquote.done'),
        ],
    ];
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" data-theme="dark" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $desc }}">
    @if (! empty($seo['noindex']))<meta name="robots" content="noindex, follow">@endif
    <link rel="canonical" href="{{ $canonical }}">
    <link rel="alternate" hreflang="en" href="{{ Site::absolute(Site::route($routeName, $params, 'en')) }}">
    <link rel="alternate" hreflang="ar" href="{{ Site::absolute(Site::route($routeName, $params, 'ar')) }}">
    <link rel="alternate" hreflang="x-default" href="{{ Site::absolute(Site::route($routeName, $params, 'en')) }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ Site::company() }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $desc }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ $rtl ? 'ar_AE' : 'en_AE' }}">
    <meta property="og:image" content="{{ $seo['image'] ?? asset('images/brand/og.jpg') }}"><meta name="twitter:image" content="{{ $seo['image'] ?? asset('images/brand/og.jpg') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="#0a0908" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#f7f4ee" media="(prefers-color-scheme: light)">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand/icon-32.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/brand/icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/brand/icon-192.png') }}">
    <script>
        // Theme before first paint (no flash). Default dark; the visitor's choice is remembered in this browser only.
        (function () { var d = document.documentElement, t = null, seen = '1'; try { t = localStorage.getItem('rp-theme'); seen = sessionStorage.getItem('rp-intro'); } catch (e) {} if (t === 'light' || t === 'dark') d.setAttribute('data-theme', t); d.classList.remove('no-js');
            // Intro screen once per visit (skipped for reduced motion); removed by site.js, or after 2.6 s at the latest.
            if (!seen && !(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) { d.classList.add('is-loading'); setTimeout(function () { if (d.classList.contains('is-loading')) d.classList.add('intro-out'); d.classList.remove('is-loading'); }, 2600); } })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800{{ $rtl ? '&family=Tajawal:wght@400;500;700;800' : '' }}&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ @filemtime(public_path('css/site.css')) }}">
    @foreach (array_filter((array) ($seo['jsonld'] ?? [])) as $ld)
        <script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @endforeach
</head>
<body class="@yield('body_class')">
@include('site.partials.icons')
<div class="loader" aria-hidden="true"><div class="loader-in"><img src="{{ asset('images/brand/logo-dark-s.webp') }}" alt="" width="260" height="148"><span class="loader-bar"><i></i></span></div></div>
<div class="progress" aria-hidden="true"><i data-progress></i></div>
<a class="skip" href="#main">{{ __('site.skip') }}</a>

<header class="hdr {{ $headerSolid ? 'always' : '' }}" data-hdr>
    <div class="wrap hdr-in">
        <a href="{{ Site::route('home') }}" class="logo" aria-label="{{ Site::company() }}">@include('site.partials.logo')</a>
        <nav class="nav" aria-label="Main">
            <a href="{{ Site::route('home') }}" @if (Site::is('home')) aria-current="page" @endif>{{ __('site.nav.home') }}</a>
            @if ($sections['cars'])
                <div class="nav-dd nav-mega" data-dd>
                    <button type="button" aria-expanded="false" @if (Site::is('cars', 'car', 'type', 'brand', 'brands')) class="on" @endif>{{ __('site.nav.cars') }} <svg class="i i-s"><use href="#i-chev-d"/></svg></button>
                    <div class="nav-dd-menu">
                        <div class="mega-col">
                            <a href="{{ Site::route('cars') }}"><span class="ico"><svg class="i"><use href="#i-car"/></svg></span><span><b>{{ __('site.nav.all_cars') }}</b><small>{{ __('site.cars.title') }}</small></span></a>
                            <a href="{{ Site::route('cars', ['mode' => 'with_driver']) }}"><span class="ico"><svg class="i"><use href="#i-steering"/></svg></span><span><b>{{ __('site.common.with_chauffeur') }}</b><small>{{ __('site.home.chauffeur_text') }}</small></span></a>
                        </div>
                        @if (count($navTypes))
                            <div class="mega-col"><span class="mega-h">{{ __('site.nav.types') }}</span>
                                @foreach ($navTypes as $t)<a class="mega-link" href="{{ Site::route('type', ['slug' => $t['slug']]) }}">{{ $t['name'] }} <small>{{ $t['count'] }}</small></a>@endforeach
                            </div>
                        @endif
                        @if (count($navBrands))
                            <div class="mega-col"><span class="mega-h">{{ __('site.nav.brands') }}</span>
                                @foreach (array_slice($navBrands, 0, 8) as $b)<a class="mega-link" href="{{ Site::route('brand', ['slug' => $b['slug']]) }}">{{ $b['name'] }} <small>{{ $b['count'] }}</small></a>@endforeach
                                <a class="mega-link gold" href="{{ Site::route('brands') }}">{{ __('site.common.view_all') }} →</a>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
            <div class="nav-dd" data-dd>
                <button type="button" aria-expanded="false" @if (Site::is('services', 'service')) class="on" @endif>{{ __('site.nav.services') }} <svg class="i i-s"><use href="#i-chev-d"/></svg></button>
                <div class="nav-dd-menu">
                    @foreach ($navServices as $slug => $svc)
                        <a href="{{ Site::route('service', ['slug' => $slug]) }}"><span class="ico"><svg class="i"><use href="#i-{{ $svc['icon'] }}"/></svg></span><span><b>{{ __('site.services.items.'.$slug.'.title') }}</b></span></a>
                    @endforeach
                    @if ($sections['limousine'])<a href="{{ Site::route('limousine') }}"><span class="ico"><svg class="i"><use href="#i-plane"/></svg></span><span><b>{{ __('site.nav.limousine') }}</b></span></a>@endif
                    @if ($sections['trips'])<a href="{{ Site::route('trips') }}"><span class="ico"><svg class="i"><use href="#i-anchor"/></svg></span><span><b>{{ __('site.nav.trips') }}</b></span></a>@endif
                    @if ($sections['packages'])<a href="{{ Site::route('packages') }}"><span class="ico"><svg class="i"><use href="#i-sparkle"/></svg></span><span><b>{{ __('site.nav.packages') }}</b></span></a>@endif
                </div>
            </div>
            @if ($sections['yachts'])<a href="{{ Site::route('yachts') }}" @if (Site::is('yachts', 'yacht')) aria-current="page" @endif>{{ __('site.nav.yachts') }}</a>@endif
            <div class="nav-dd" data-dd>
                <button type="button" aria-expanded="false" @if (Site::is('about', 'how', 'gallery', 'faq', 'offers', 'blog', 'post', 'areas', 'area')) class="on" @endif>{{ __('site.nav.more') }} <svg class="i i-s"><use href="#i-chev-d"/></svg></button>
                <div class="nav-dd-menu nav-dd-s">
                    <a href="{{ Site::route('offers') }}"><span class="ico"><svg class="i"><use href="#i-tag"/></svg></span><span><b>{{ __('site.nav.offers') }}</b></span></a>
                    <a href="{{ Site::route('areas') }}"><span class="ico"><svg class="i"><use href="#i-pin"/></svg></span><span><b>{{ __('site.nav.areas') }}</b></span></a>
                    <a href="{{ Site::route('blog') }}"><span class="ico"><svg class="i"><use href="#i-info"/></svg></span><span><b>{{ __('site.nav.blog') }}</b></span></a>
                    <a href="{{ Site::route('about') }}"><span><b>{{ __('site.nav.about') }}</b></span></a>
                    <a href="{{ Site::route('how') }}"><span><b>{{ __('site.nav.how') }}</b></span></a>
                    <a href="{{ Site::route('gallery') }}"><span><b>{{ __('site.nav.gallery') }}</b></span></a>
                    <a href="{{ Site::route('faq') }}"><span><b>{{ __('site.nav.faq') }}</b></span></a>
                    <a href="{{ config('website.account_url') }}" rel="nofollow"><span><b>{{ __('site.nav.account') }}</b></span></a>
                </div>
            </div>
            <a href="{{ Site::route('contact') }}" @if (Site::is('contact')) aria-current="page" @endif>{{ __('site.nav.contact') }}</a>
        </nav>
        <div class="hdr-tools">
            @if (Site::phone())<a class="hdr-phone" href="{{ Site::tel() }}"><svg class="i i-s gold"><use href="#i-phone"/></svg>{{ Site::phone() }}</a>@endif
            <a class="tool tool-lang" href="{{ Site::switchUrl() }}" hreflang="{{ $rtl ? 'en' : 'ar' }}" lang="{{ $rtl ? 'en' : 'ar' }}" aria-label="{{ __('site.nav.language_label') }}">{{ __('site.nav.language') }}</a>
            <button type="button" class="tool" data-theme-toggle aria-label="{{ __('site.nav.theme_light') }}" data-label-dark="{{ __('site.nav.theme_dark') }}" data-label-light="{{ __('site.nav.theme_light') }}">
                <svg class="i theme-moon"><use href="#i-sun"/></svg><svg class="i theme-sun"><use href="#i-moon"/></svg>
            </button>
            <button type="button" class="btn btn-red btn-s hdr-book" data-open-quote>{{ __('site.nav.quote') }}</button>
            <button type="button" class="tool burger" data-drawer-open aria-label="{{ __('site.nav.menu') }}" aria-controls="drawer" aria-expanded="false"><svg class="i"><use href="#i-menu"/></svg></button>
        </div>
    </div>
</header>

<div class="drawer" id="drawer" data-drawer aria-hidden="true">
    <div class="drawer-bg" data-drawer-close></div>
    <div class="drawer-panel" role="dialog" aria-modal="true" aria-label="{{ __('site.nav.menu') }}">
        <div class="drawer-head">
            <a href="{{ Site::route('home') }}" class="logo">@include('site.partials.logo')</a>
            <button type="button" class="tool" data-drawer-close aria-label="{{ __('site.nav.close') }}"><svg class="i"><use href="#i-x"/></svg></button>
        </div>
        <button type="button" class="btn btn-red btn-block" data-open-quote data-drawer-close>{{ __('site.nav.quote') }}</button>
        <a href="{{ Site::route('home') }}" @if (Site::is('home')) aria-current="page" @endif><svg class="i ico"><use href="#i-star"/></svg>{{ __('site.nav.home') }}</a>
        @if ($sections['cars'])
            <a href="{{ Site::route('cars') }}" @if (Site::is('cars', 'car')) aria-current="page" @endif><svg class="i ico"><use href="#i-car"/></svg>{{ __('site.nav.all_cars') }}</a>
            <details class="drawer-sub"><summary><svg class="i ico"><use href="#i-grid"/></svg>{{ __('site.nav.types') }}<svg class="i i-s"><use href="#i-chev-d"/></svg></summary>
                @foreach ($navTypes as $t)<a href="{{ Site::route('type', ['slug' => $t['slug']]) }}">{{ $t['name'] }}</a>@endforeach
            </details>
            <details class="drawer-sub"><summary><svg class="i ico"><use href="#i-tag"/></svg>{{ __('site.nav.brands') }}<svg class="i i-s"><use href="#i-chev-d"/></svg></summary>
                @foreach ($navBrands as $b)<a href="{{ Site::route('brand', ['slug' => $b['slug']]) }}">{{ $b['name'] }}</a>@endforeach
            </details>
        @endif
        <details class="drawer-sub"><summary><svg class="i ico"><use href="#i-sparkle"/></svg>{{ __('site.nav.services') }}<svg class="i i-s"><use href="#i-chev-d"/></svg></summary>
            @foreach ($navServices as $slug => $svc)<a href="{{ Site::route('service', ['slug' => $slug]) }}">{{ __('site.services.items.'.$slug.'.title') }}</a>@endforeach
        </details>
        @if ($sections['yachts'])<a href="{{ Site::route('yachts') }}" @if (Site::is('yachts', 'yacht')) aria-current="page" @endif><svg class="i ico"><use href="#i-yacht"/></svg>{{ __('site.nav.yachts') }}</a>@endif
        @if ($sections['trips'])<a href="{{ Site::route('trips') }}"><svg class="i ico"><use href="#i-anchor"/></svg>{{ __('site.nav.trips') }}</a>@endif
        @if ($sections['packages'])<a href="{{ Site::route('packages') }}"><svg class="i ico"><use href="#i-sparkle"/></svg>{{ __('site.nav.packages') }}</a>@endif
        @if ($sections['limousine'])<a href="{{ Site::route('limousine') }}" @if (Site::is('limousine')) aria-current="page" @endif><svg class="i ico"><use href="#i-plane"/></svg>{{ __('site.nav.limousine') }}</a>@endif
        <a href="{{ Site::route('offers') }}"><svg class="i ico"><use href="#i-tag"/></svg>{{ __('site.nav.offers') }}</a>
        <a href="{{ Site::route('areas') }}"><svg class="i ico"><use href="#i-pin"/></svg>{{ __('site.nav.areas') }}</a>
        <a href="{{ Site::route('blog') }}"><svg class="i ico"><use href="#i-info"/></svg>{{ __('site.nav.blog') }}</a>
        <a href="{{ Site::route('how') }}"><svg class="i ico"><use href="#i-route"/></svg>{{ __('site.nav.how') }}</a>
        <a href="{{ Site::route('gallery') }}"><svg class="i ico"><use href="#i-instagram"/></svg>{{ __('site.nav.gallery') }}</a>
        <a href="{{ Site::route('about') }}"><svg class="i ico"><use href="#i-info"/></svg>{{ __('site.nav.about') }}</a>
        <a href="{{ Site::route('faq') }}"><svg class="i ico"><use href="#i-info"/></svg>{{ __('site.nav.faq') }}</a>
        <a href="{{ Site::route('contact') }}"><svg class="i ico"><use href="#i-mail"/></svg>{{ __('site.nav.contact') }}</a>
        <a href="{{ config('website.account_url') }}" rel="nofollow"><svg class="i ico"><use href="#i-user"/></svg>{{ __('site.nav.account') }}</a>
        <div class="drawer-foot">
            @if (Site::tel())<a class="btn btn-ghost" href="{{ Site::tel() }}"><svg class="i"><use href="#i-phone"/></svg><span class="ltr">{{ Site::phone() }}</span></a>@endif
            @if ($wa)<a class="btn btn-wa" href="{{ $wa }}" target="_blank" rel="noopener"><svg class="i"><use href="#i-whatsapp"/></svg>{{ __('site.nav.whatsapp') }}</a>@endif
        </div>
    </div>
</div>

<main id="main">
    @yield('content')
</main>

<footer class="ftr">
    <div class="wrap">
        <div class="ftr-grid">
            <div>
                <a href="{{ Site::route('home') }}" class="logo ftr-logo"><img src="{{ asset('images/brand/logo-dark.webp') }}" alt="{{ Site::company() }}" width="520" height="295" loading="lazy"></a>
                <p>{{ __('site.footer.about') }}</p>
                @if ($socials = Site::socials())
                    <div class="socials">
                        @foreach ($socials as $net => $url)
                            <a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ ucfirst($net) }}"><svg class="i"><use href="#i-{{ $net }}"/></svg></a>
                        @endforeach
                        @if ($wa)<a href="{{ $wa }}" target="_blank" rel="noopener" aria-label="WhatsApp"><svg class="i"><use href="#i-whatsapp"/></svg></a>@endif
                    </div>
                @endif
            </div>
            <div>
                <h3>{{ __('site.footer.rent') }}</h3>
                <ul>
                    @if ($sections['cars'])<li><a href="{{ Site::route('cars') }}">{{ __('site.nav.cars') }}</a></li><li><a href="{{ Site::route('cars', ['mode' => 'with_driver']) }}">{{ __('site.common.with_chauffeur') }}</a></li>@endif
                    @if ($sections['yachts'])<li><a href="{{ Site::route('yachts') }}">{{ __('site.nav.yachts') }}</a></li>@endif
                    @if ($sections['trips'])<li><a href="{{ Site::route('trips') }}">{{ __('site.nav.trips') }}</a></li>@endif
                    @if ($sections['packages'])<li><a href="{{ Site::route('packages') }}">{{ __('site.nav.packages') }}</a></li>@endif
                    @if ($sections['limousine'])<li><a href="{{ Site::route('limousine') }}">{{ __('site.nav.limousine') }}</a></li>@endif
                    @foreach (array_slice($navTypes, 0, 4) as $t)<li><a href="{{ Site::route('type', ['slug' => $t['slug']]) }}">{{ $t['name'] }}</a></li>@endforeach
                    @if (count($navBrands))<li><a href="{{ Site::route('brands') }}">{{ __('site.nav.brands') }}</a></li>@endif
                </ul>
            </div>
            <div>
                <h3>{{ __('site.nav.services') }}</h3>
                <ul>
                    @foreach ($navServices as $slug => $svc)<li><a href="{{ Site::route('service', ['slug' => $slug]) }}">{{ __('site.services.items.'.$slug.'.title') }}</a></li>@endforeach
                </ul>
            </div>
            <div>
                <h3>{{ __('site.footer.company') }}</h3>
                <ul>
                    <li><a href="{{ Site::route('offers') }}">{{ __('site.nav.offers') }}</a></li>
                    <li><a href="{{ Site::route('areas') }}">{{ __('site.nav.areas') }}</a></li>
                    <li><a href="{{ Site::route('blog') }}">{{ __('site.nav.blog') }}</a></li>
                    <li><a href="{{ Site::route('about') }}">{{ __('site.nav.about') }}</a></li>
                    <li><a href="{{ Site::route('how') }}">{{ __('site.nav.how') }}</a></li>
                    <li><a href="{{ Site::route('gallery') }}">{{ __('site.nav.gallery') }}</a></li>
                    <li><a href="{{ Site::route('faq') }}">{{ __('site.nav.faq') }}</a></li>
                    <li><a href="{{ Site::route('contact') }}">{{ __('site.nav.contact') }}</a></li>
                    <li><a href="{{ Site::route('terms') }}">{{ __('site.legal.terms_title') }}</a></li>
                    <li><a href="{{ Site::route('privacy') }}">{{ __('site.legal.privacy_title') }}</a></li>
                    <li><a href="{{ config('website.account_url') }}" rel="nofollow">{{ __('site.nav.account') }}</a></li>
                </ul>
            </div>
            <div>
                <h3>{{ __('site.footer.contact') }}</h3>
                <ul class="ftr-contact">
                    @if (Site::phone())<li><svg class="i"><use href="#i-phone"/></svg><a href="{{ Site::tel() }}" class="ltr">{{ Site::phone() }}</a></li>@endif
                    @if ($wa)<li><svg class="i"><use href="#i-whatsapp"/></svg><a href="{{ $wa }}" target="_blank" rel="noopener">{{ __('site.nav.whatsapp') }}</a></li>@endif
                    @if (Site::email())<li><svg class="i"><use href="#i-mail"/></svg><a href="mailto:{{ Site::email() }}">{{ Site::email() }}</a></li>@endif
                    <li><svg class="i"><use href="#i-pin"/></svg>@if (Site::mapUrl())<a href="{{ Site::mapUrl() }}" target="_blank" rel="noopener">{{ Site::address() ?: __('site.footer.city') }}</a>@else<span>{{ Site::address() ?: __('site.footer.city') }}</span>@endif</li>
                </ul>
            </div>
        </div>
        <div class="ftr-bottom">
            <span>© {{ now()->year }} {{ Site::company() }}. {{ __('site.footer.rights') }}</span>
            <span><a href="{{ Site::switchUrl() }}" lang="{{ $rtl ? 'en' : 'ar' }}">{{ __('site.nav.language') }}</a> · {{ __('site.footer.city') }}</span>
        </div>
    </div>
</footer>

@if ($wa)<a class="fab" href="{{ $wa }}" target="_blank" rel="noopener" aria-label="{{ __('site.nav.whatsapp') }}"><svg class="i"><use href="#i-whatsapp"/></svg></a>@endif
<button type="button" class="totop" data-totop aria-label="{{ __('site.nav.top') }}"><svg class="i"><use href="#i-arrow-up"/></svg></button>

@include('site.partials.quote-modal')

<div class="lb" data-lb role="dialog" aria-modal="true" aria-label="{{ __('site.car.gallery') }}">
    <button type="button" class="lb-close" data-lb-close aria-label="{{ __('site.common.close') }}"><svg class="i"><use href="#i-x"/></svg></button>
    <button type="button" class="lb-prev" data-lb-prev aria-label="{{ __('site.common.previous') }}"><svg class="i flip"><use href="#i-chev-l"/></svg></button>
    <img alt="" data-lb-img>
    <button type="button" class="lb-next" data-lb-next aria-label="{{ __('site.common.next') }}"><svg class="i flip"><use href="#i-chev-r"/></svg></button>
</div>
<div class="toast" data-toast role="status" aria-live="polite"></div>

<script>
    window.RP = {!! json_encode($rpConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!};
</script>
<script src="{{ asset('js/site.js') }}?v={{ @filemtime(public_path('js/site.js')) }}" defer></script>
@stack('scripts')
</body>
</html>
