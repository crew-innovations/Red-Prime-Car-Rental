@extends('site.layout')
@php
    use App\Support\Site\Site;
    $s = Site::sections();
    $today = now()->toDateString();
    $tomorrow = now()->addDay()->toDateString();
    $after = now()->addDays(3)->toDateString();
    $times = collect(range(0, 47))->map(fn ($i) => sprintf('%02d:%02d', intdiv($i, 2), $i % 2 ? 30 : 0));
    $heroImg = $hero['images']['cover']['large'] ?? null;
    $wa = Site::whatsapp(__('site.wa.general'));
    $heroSlides = array_values(array_filter([$heroImg, Site::img('fleet-garage.webp'), Site::img('photo-lambo-night.jpg'), Site::img('fleet-s580.webp')]));
    $types = \App\Support\Site\Catalog::types($cars);
@endphp

@section('content')
<section class="hero hero-cine" data-spot>
    <div class="hero-bg hero-slides" data-slides>@foreach (array_slice($heroSlides, 0, 4) as $i => $src)<img src="{{ $src }}" alt="" @if ($i === 0) fetchpriority="high" class="on" @else loading="lazy" @endif>@endforeach</div>
    <div class="hero-grid" aria-hidden="true"></div>
    <div class="hero-word" aria-hidden="true">RED PRIME</div>
    <div class="wrap hero-in">
        <div>
            <span class="eyebrow">{{ __('site.home.eyebrow') }}</span>
            <h1 data-split data-split-now>{{ __('site.home.title_1') }} <span class="accent">{{ __('site.home.title_accent') }}</span><br>{{ __('site.home.title_2') }}</h1>
            <p class="lead">{{ __('site.home.lead') }}</p>
            <div class="hero-cta">
                <a class="btn btn-red btn-l magnetic" href="{{ $s['cars'] ? Site::route('cars') : Site::route('contact') }}">{{ __('site.home.cta_fleet') }} <svg class="i flip"><use href="#i-arrow"/></svg></a>
                <button type="button" class="btn btn-glass btn-l" data-open-quote>{{ __('site.nav.quote') }}</button>
                @if ($wa)<a class="btn btn-wa btn-l" href="{{ $wa }}" target="_blank" rel="noopener"><svg class="i"><use href="#i-whatsapp"/></svg>{{ __('site.nav.whatsapp') }}</a>@endif
            </div>
            <div class="stats stagger">
                @if (count($cars))<div class="stat rv"><b data-count="{{ count($cars) }}">{{ count($cars) }}</b><span>{{ __('site.home.stat_cars') }}</span></div>@endif
                @if (count($brands))<div class="stat rv"><b data-count="{{ count($brands) }}">{{ count($brands) }}</b><span>{{ __('site.home.stat_brands') }}</span></div>@endif
                @if ($s['yachts'])<div class="stat rv"><b>{{ \App\Models\Yacht::query()->publiclyVisible()->count() }}</b><span>{{ __('site.home.stat_yachts') }}</span></div>@endif
                <div class="stat rv"><b>{{ __('site.home.stat_modes_value') }}</b><span>{{ __('site.home.stat_modes') }}</span></div>
            </div>
        </div>

        <div class="finder rv" data-rv="left" data-tabs>
            <h2><svg class="i gold"><use href="#i-search"/></svg>{{ __('site.home.search_title') }}</h2>
            <div class="tabs" role="tablist">
                @if ($s['cars'])<button type="button" class="tab" role="tab" aria-selected="true" aria-controls="ft-cars" id="ftb-cars"><svg class="i i-s"><use href="#i-car"/></svg>{{ __('site.home.tab_cars') }}</button>@endif
                @if ($s['yachts'])<button type="button" class="tab" role="tab" aria-selected="{{ $s['cars'] ? 'false' : 'true' }}" aria-controls="ft-yachts" id="ftb-yachts"><svg class="i i-s"><use href="#i-yacht"/></svg>{{ __('site.home.tab_yachts') }}</button>@endif
                @if ($s['cars'])<button type="button" class="tab" role="tab" aria-selected="false" aria-controls="ft-chauffeur" id="ftb-chauffeur"><svg class="i i-s"><use href="#i-steering"/></svg>{{ __('site.home.tab_chauffeur') }}</button>@endif
                @if ($s['limousine'])<button type="button" class="tab" role="tab" aria-selected="false" aria-controls="ft-transfer" id="ftb-transfer"><svg class="i i-s"><use href="#i-plane"/></svg>{{ __('site.home.tab_transfer') }}</button>@endif
            </div>

            @if ($s['cars'])
            <form class="tab-panel on" id="ft-cars" role="tabpanel" aria-labelledby="ftb-cars" action="{{ Site::route('cars') }}" method="get" data-dt-form>
                <div class="field">
                    <span class="label">{{ __('site.home.pickup') }}</span>
                    <div class="dt">
                        <input class="input" type="date" name="from_d" value="{{ $tomorrow }}" min="{{ $today }}" aria-label="{{ __('site.home.pickup') }} {{ __('site.home.date') }}" required>
                        <select class="select" name="from_t" aria-label="{{ __('site.home.pickup') }} {{ __('site.home.time') }}">@foreach ($times as $t)<option @selected($t === '10:00')>{{ $t }}</option>@endforeach</select>
                    </div>
                </div>
                <div class="field">
                    <span class="label">{{ __('site.home.return') }}</span>
                    <div class="dt">
                        <input class="input" type="date" name="to_d" value="{{ $after }}" min="{{ $today }}" aria-label="{{ __('site.home.return') }} {{ __('site.home.date') }}" required>
                        <select class="select" name="to_t" aria-label="{{ __('site.home.return') }} {{ __('site.home.time') }}">@foreach ($times as $t)<option @selected($t === '10:00')>{{ $t }}</option>@endforeach</select>
                    </div>
                </div>
                <input type="hidden" name="from"><input type="hidden" name="to"><input type="hidden" name="available" value="1">
                <div class="field">
                    <span class="label">{{ __('site.home.how') }}</span>
                    <div class="seg">
                        <label><input type="radio" name="mode" value="" checked>{{ __('site.home.any') }}</label>
                        <label><input type="radio" name="mode" value="self_drive">{{ __('site.common.self_drive') }}</label>
                        <label><input type="radio" name="mode" value="with_driver">{{ __('site.common.with_chauffeur') }}</label>
                    </div>
                </div>
                <button class="btn btn-red btn-block btn-l" type="submit">{{ __('site.home.search_cars') }} <svg class="i flip"><use href="#i-arrow"/></svg></button>
            </form>
            @endif

            @if ($s['yachts'])
            <form class="tab-panel {{ $s['cars'] ? '' : 'on' }}" id="ft-yachts" role="tabpanel" aria-labelledby="ftb-yachts" action="{{ Site::route('yachts') }}" method="get">
                <div class="field">
                    <label for="ft-guests">{{ __('site.home.guests') }}</label>
                    <div class="stepper" data-stepper><button type="button" data-step="-1" aria-label="-">−</button><input id="ft-guests" type="number" name="guests" value="6" min="1" max="200"><button type="button" data-step="1" aria-label="+">+</button></div>
                </div>
                <p class="muted small">{{ __('site.yachts.lead') }}</p>
                <button class="btn btn-red btn-block btn-l" type="submit">{{ __('site.home.search_yachts') }} <svg class="i flip"><use href="#i-arrow"/></svg></button>
            </form>
            @endif

            @if ($s['cars'])
            <div class="tab-panel" id="ft-chauffeur" role="tabpanel" aria-labelledby="ftb-chauffeur">
                <p class="muted">{{ __('site.home.chauffeur_text') }}</p>
                <a class="btn btn-red btn-block btn-l" href="{{ Site::route('cars', ['mode' => 'with_driver']) }}">{{ __('site.home.search_chauffeur') }} <svg class="i flip"><use href="#i-arrow"/></svg></a>
            </div>
            @endif

            @if ($s['limousine'])
            <div class="tab-panel" id="ft-transfer" role="tabpanel" aria-labelledby="ftb-transfer">
                <p class="muted">{{ __('site.home.transfer_text') }}</p>
                <a class="btn btn-red btn-block btn-l" href="{{ Site::route('limousine') }}">{{ __('site.home.search_transfer') }} <svg class="i flip"><use href="#i-arrow"/></svg></a>
            </div>
            @endif

            @if (! $s['cars'] && ! $s['yachts'])
                <div class="tab-panel on"><a class="btn btn-red btn-block btn-l" href="{{ Site::route('contact') }}">{{ __('site.nav.contact') }}</a></div>
            @endif
        </div>
    </div>
    <div class="hero-foot wrap" aria-hidden="true">
        <span class="scroll-cue"><span class="scroll-mouse"><i></i></span>{{ __('site.x.scroll') }}</span>
        <span class="slide-dots" data-slide-dots>@foreach (array_slice($heroSlides, 0, 4) as $i => $src)<i class="{{ $i === 0 ? 'on' : '' }}"></i>@endforeach</span>
    </div>
</section>

@php $logoBrands = array_filter($brandList, fn ($b) => $b['logo']); @endphp
@if (count($logoBrands))
<section class="marquee marquee-logos" aria-label="{{ __('site.home.brands_title') }}">
    <div class="marquee-track">
        @foreach (range(1, 2) as $rep)
            @foreach ($logoBrands as $b)<a class="marquee-logo" href="{{ Site::route('brand', ['slug' => $b['slug']]) }}" @if ($rep > 1) aria-hidden="true" tabindex="-1" @endif><img src="{{ $b['logo'] }}" alt="{{ $b['name'] }}" loading="lazy"></a>@endforeach
        @endforeach
    </div>
</section>
@endif


{{-- Occasions (30 Oct) --}}
<section class="sec occ-sec">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.x.occasions_eyebrow') }}</span><h2 data-split>{{ __('site.x.occasions_title') }}</h2><p>{{ __('site.x.occasions_text') }}</p></div></div>
        <div class="occ-grid stagger">
            @foreach (Site::OCCASIONS as $k => $o)
                @continue($o['service'] === 'yacht' && ! $s['yachts'])
                @php $ot = __('site.x.occasions.'.$k); @endphp
                <button type="button" class="occ rv tilt" data-open-quote data-service="{{ $o['service'] }}" data-item="{{ $ot['item'] }}">
                    <img src="{{ Site::img($o['image']) }}" alt="" loading="lazy">
                    <span class="occ-ico"><svg class="i"><use href="#i-{{ $o['icon'] }}"/></svg></span>
                    <span class="occ-txt"><b>{{ $ot['title'] }}</b><small>{{ $ot['text'] }}</small><span class="occ-cta">{{ __('site.x.occasion_cta') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></span></span>
                </button>
            @endforeach
        </div>
    </div>
</section>

@if (count($types))
<section class="sec">
    <div class="wrap">
        <div class="sec-head rv">
            <div><span class="eyebrow">{{ __('site.home.categories_eyebrow') }}</span><h2>{{ __('site.home.categories_title') }}</h2></div>
            <a class="link-more" href="{{ Site::route('cars') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a>
        </div>
        <div class="cats stagger">
            @foreach ($types as $t)
                <a class="cat rv tilt" href="{{ Site::route('type', ['slug' => $t['slug']]) }}">
                    <img src="{{ Site::typeImage($t['name'], $cars) }}" alt="" loading="lazy">
                    <span class="arrow"><svg class="i flip"><use href="#i-arrow"/></svg></span>
                    <b>{{ $t['name'] }}</b>
                    <span>{{ trans_choice('site.home.cars_count', $t['count'], ['count' => $t['count']]) }}</span>
                </a>
            @endforeach
            @if ($s['cars'])
                <a class="cat rv" href="{{ Site::route('cars', ['mode' => 'with_driver']) }}">
                    <img src="{{ Site::img('fleet-s580.webp') }}" alt="" loading="lazy">
                    <span class="arrow"><svg class="i flip"><use href="#i-arrow"/></svg></span>
                    <b>{{ __('site.common.with_chauffeur') }}</b><span>{{ __('site.services.items.chauffeur-service.title') }}</span>
                </a>
            @endif
            @if ($s['yachts'])
                @php $yImg = $yachts->first()['images']['cover']['thumb'] ?? Site::yachtImage(true); @endphp
                <a class="cat rv" href="{{ Site::route('yachts') }}">
                    <img src="{{ $yImg }}" alt="" loading="lazy">
                    <span class="arrow"><svg class="i flip"><use href="#i-arrow"/></svg></span>
                    <b>{{ __('site.nav.yachts') }}</b><span>{{ __('site.home.yacht_title') }}</span>
                </a>
            @endif
        </div>
    </div>
</section>
@endif

{{-- Cinematic statement band (30 Oct) --}}
<section class="statement">
    <div class="statement-bg" data-parallax><img src="{{ Site::img('fleet-garage.webp') }}" alt="" loading="lazy"></div>
    <div class="wrap statement-in">
        <span class="eyebrow rv">{{ __('site.x.statement_eyebrow') }}</span>
        <h2 class="statement-title" data-split>{{ __('site.x.statement_title') }}</h2>
        <p class="rv">{{ __('site.x.statement_text') }}</p>
        <div class="hero-cta rv" style="justify-content:center">
            <a class="btn btn-red btn-l magnetic" href="{{ $s['cars'] ? Site::route('cars') : Site::route('contact') }}">{{ __('site.x.statement_cta') }} <svg class="i flip"><use href="#i-arrow"/></svg></a>
            <button type="button" class="btn btn-glass btn-l" data-open-quote>{{ __('site.nav.quote') }}</button>
        </div>
    </div>
</section>

<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv">
            <div><span class="eyebrow">{{ __('site.home.services_eyebrow') }}</span><h2>{{ __('site.home.services_title') }}</h2><p>{{ __('site.services.lead') }}</p></div>
            <a class="link-more" href="{{ Site::route('services') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a>
        </div>
        @include('site.partials.service-cards', ['services' => array_slice(Site::services(), 0, 4, true)])
    </div>
</section>

@if (count($featured))
<section class="sec" data-carousel-wrap>
    <div class="wrap">
        <div class="sec-head rv">
            <div><span class="eyebrow">{{ __('site.home.fleet_eyebrow') }}</span><h2>{{ __('site.home.fleet_title') }}</h2><p>{{ __('site.home.fleet_text') }}</p></div>
            <div class="flex center gap-1">
                <a class="link-more" href="{{ Site::route('cars') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a>
                <div class="carousel-nav">
                    <button type="button" data-car-prev aria-label="{{ __('site.common.previous') }}"><svg class="i flip"><use href="#i-chev-l"/></svg></button>
                    <button type="button" data-car-next aria-label="{{ __('site.common.next') }}"><svg class="i flip"><use href="#i-chev-r"/></svg></button>
                </div>
            </div>
        </div>
        @if (count($bodies) > 1)
            <div class="filter-row" role="group" aria-label="{{ __('site.cars.body') }}" data-chip-filter>
                <button type="button" class="chip on" aria-pressed="true" data-filter="">{{ __('site.home.all') }}</button>
                @foreach ($bodies as $body => $n)<button type="button" class="chip" aria-pressed="false" data-filter="{{ $body }}">{{ $body }}</button>@endforeach
            </div>
        @endif
        <div class="carousel"><div class="carousel-track" data-carousel>
            @foreach ($featured as $car)@include('site.partials.car-card', ['car' => $car])@endforeach
        </div></div>
    </div>
</section>
@endif

@if ($s['cars'] && count($cars) > 1)
@php
    $fc = $cars->map(fn ($c) => ['n' => $c['name'], 'b' => $c['brand'] ?? '', 't' => $c['body'] ?? '', 's' => (int) ($c['seats'] ?? 0),
        'p' => \App\Support\Site\Catalog::carFrom($c), 'sd' => ! empty($c['self_drive']), 'wd' => ! empty($c['with_driver']),
        'i' => $c['images']['cover']['thumb'] ?? null, 'u' => Site::route('car', ['slug' => $c['slug']])])->values();
    $prices = $fc->pluck('p')->filter()->sort()->values();
    $steps = collect([0.35, 0.7])->map(fn ($q) => $prices->count() ? (int) (ceil($prices[(int) floor(($prices->count() - 1) * $q)] / 500) * 500) : null)->filter()->unique()->values();
    $seatOpts = $fc->pluck('s')->filter()->unique()->sort()->values();
    $finderT = ['zero' => trans_choice('site.x.finder_result', 0), 'one' => trans_choice('site.x.finder_result', 1), 'many' => str_replace('99', ':count', trans_choice('site.x.finder_result', 99)), 'from' => __('site.common.from'), 'per_day' => __('site.common.per_day')];
@endphp
<section class="sec finder-sec">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.x.finder_eyebrow') }}</span><h2 data-split>{{ __('site.x.finder_title') }}</h2><p>{{ __('site.x.finder_text') }}</p></div></div>
        <div class="cfinder rv" data-cfinder data-cars='@json($fc)' data-t='@json($finderT)' data-all="{{ Site::route('cars') }}">
            <div class="cf-controls">
                <div class="cf-group"><span class="label">{{ __('site.x.finder_budget') }}</span>
                    <div class="chips"><button type="button" class="chip on" data-cf="max" data-v="">{{ __('site.x.finder_any') }}</button>@foreach ($steps as $st)<button type="button" class="chip" data-cf="max" data-v="{{ $st }}">{{ __('site.x.finder_up_to', ['price' => Site::money($st)]) }}</button>@endforeach</div>
                </div>
                @if (count($seatOpts) > 1)
                <div class="cf-group"><span class="label">{{ __('site.x.finder_seats') }}</span>
                    <div class="chips"><button type="button" class="chip on" data-cf="seats" data-v="">{{ __('site.x.finder_any') }}</button>@foreach ($seatOpts as $so)<button type="button" class="chip" data-cf="seats" data-v="{{ $so }}">{{ $so }}+</button>@endforeach</div>
                </div>
                @endif
                @if (count($types) > 1)
                <div class="cf-group"><span class="label">{{ __('site.x.finder_style') }}</span>
                    <div class="chips"><button type="button" class="chip on" data-cf="type" data-v="">{{ __('site.x.finder_any') }}</button>@foreach ($types as $t)<button type="button" class="chip" data-cf="type" data-v="{{ $t['name'] }}">{{ $t['name'] }}</button>@endforeach</div>
                </div>
                @endif
                <div class="cf-group"><span class="label">{{ __('site.x.finder_mode') }}</span>
                    <div class="chips"><button type="button" class="chip on" data-cf="mode" data-v="">{{ __('site.x.finder_any') }}</button><button type="button" class="chip" data-cf="mode" data-v="self_drive">{{ __('site.common.self_drive') }}</button><button type="button" class="chip" data-cf="mode" data-v="with_driver">{{ __('site.common.with_chauffeur') }}</button></div>
                </div>
            </div>
            <div class="cf-out">
                <div class="cf-head"><b data-cf-count aria-live="polite"></b><button type="button" class="link-more" data-cf-reset>{{ __('site.x.finder_reset') }}</button></div>
                <div class="cf-list" data-cf-list></div>
                <p class="muted small" data-cf-none hidden>{{ __('site.x.finder_none') }}</p>
                <a class="btn btn-red btn-block" data-cf-all href="{{ Site::route('cars') }}">{{ __('site.x.finder_all') }} <svg class="i flip"><use href="#i-arrow"/></svg></a>
            </div>
        </div>
    </div>
</section>
@endif

<section class="sec">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.home.why_eyebrow') }}</span><h2>{{ __('site.home.why_title') }}</h2></div></div>
        <div class="features">
            @foreach (__('site.home.why') as $w)
                <div class="feature rv"><div class="ico"><svg class="i i-l"><use href="#i-{{ $w['icon'] }}"/></svg></div><h3>{{ $w['title'] }}</h3><p>{{ $w['text'] }}</p></div>
            @endforeach
        </div>
    </div>
</section>

@if (count($offers))
<section class="sec">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.x.offers_eyebrow') }}</span><h2 data-split>{{ __('site.x.offers_title') }}</h2></div><a class="link-more" href="{{ Site::route('offers') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        <div class="offer-grid">@foreach (array_slice($offers, 0, 3) as $o)@include('site.partials.offer-card', ['o' => $o])@endforeach</div>
    </div>
</section>
@endif

<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv" style="justify-content:center;text-align:center"><div><span class="eyebrow">{{ __('site.home.steps_eyebrow') }}</span><h2>{{ __('site.home.steps_title') }}</h2></div></div>
        <ol class="steps">
            @foreach (__('site.home.steps') as $i => $st)
                <li class="step rv"><div class="step-n">{{ $i + 1 }}</div><h3>{{ $st['title'] }}</h3><p>{{ $st['text'] }}</p></li>
            @endforeach
        </ol>
    </div>
</section>

@if ($s['yachts'] && count($yachts))
@php $y = $yachts->first(); $yp = $y['prices'] ?? []; @endphp
<section class="sec">
    <div class="wrap split">
        <div class="spot-media rv reveal-img">
            @include('site.partials.media', ['img' => $y['images']['cover'] ?? ['url' => Site::yachtImage(), 'large' => Site::yachtImage()], 'alt' => __('site.nav.yachts'), 'kind' => 'yacht', 'size' => 'large'])
            <div class="spot-float">
                @if (! empty($y['guests']))<div class="pill"><small>{{ __('site.yachts.capacity') }}</small><b>{{ $y['guests'] }}</b></div>@endif
                @if (! empty($y['length_ft']))<div class="pill"><small>{{ __('site.yachts.length') }}</small><b>{{ __('site.common.ft', ['n' => (float) $y['length_ft']]) }}</b></div>@endif
            </div>
        </div>
        <div class="rv">
            <span class="eyebrow">{{ __('site.home.yacht_eyebrow') }}</span>
            <h2 style="font-size:clamp(30px,4vw,50px);font-weight:700;margin:12px 0 14px">{{ __('site.home.yacht_title') }}</h2>
            <p class="muted">{{ __('site.home.yacht_text') }}</p>
            <div class="price-chips">
                @foreach (['hourly', 'half_day', 'sunset', 'full_day'] as $k)
                    @if (! empty($yp[$k]))<div class="price-chip"><small>{{ __('site.yachts.'.$k) }}</small><b>{{ Site::money($yp[$k]) }}</b></div>@endif
                @endforeach
            </div>
            <ul class="ticks">
                <li><svg class="i"><use href="#i-check-c"/></svg>{{ __('site.common.captain_included') }}</li>
                @if (! empty($y['marina']))<li><svg class="i"><use href="#i-pin"/></svg>{{ $y['marina'] }}</li>@endif
                @if (count($yachts) > 1)<li><svg class="i"><use href="#i-yacht"/></svg>{{ trans_choice('site.yachts.count', count($yachts), ['count' => count($yachts)]) }}</li>@endif
            </ul>
            <div class="flex wrapf gap-1">
                <a class="btn btn-red btn-l" href="{{ Site::route('yachts') }}">{{ __('site.home.yacht_cta') }} <svg class="i flip"><use href="#i-arrow"/></svg></a>
                <a class="btn btn-ghost btn-l" href="{{ Site::route('yacht', ['slug' => $y['slug']]) }}">{{ $y['name'] }}</a>
            </div>
        </div>
    </div>
</section>
@endif

@if (count($trips))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.nav.trips') }}</span><h2>{{ __('site.home.trips_title') }}</h2></div><a class="link-more" href="{{ Site::route('trips') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        <div class="grid-cards">@foreach ($trips as $item)@include('site.partials.trip-card', ['item' => $item])@endforeach</div>
    </div>
</section>
@endif

@if ($s['limousine'])
<section class="sec">
    <div class="wrap">
        <div class="band rv">
            <div class="band-in">
                <div>
                    <span class="eyebrow">{{ __('site.home.limo_eyebrow') }}</span>
                    <h2 class="mt-1">{{ __('site.home.limo_title') }}</h2>
                    <p>{{ __('site.home.limo_text') }}</p>
                    <div class="chips-row">
                        @foreach (\App\Support\Site\Catalog::transferServices() as $svc)<a class="chip" href="{{ Site::route('limousine', ['service' => $svc['slug']]) }}">{{ $svc['name'] }}</a>@endforeach
                    </div>
                </div>
                <div class="band-actions"><a class="btn btn-gold btn-l" href="{{ Site::route('limousine') }}">{{ __('site.home.limo_cta') }} <svg class="i flip"><use href="#i-arrow"/></svg></a></div>
            </div>
        </div>
    </div>
</section>
@endif

@if (count($packages))
<section class="sec">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.nav.packages') }}</span><h2>{{ __('site.home.packages_title') }}</h2></div><a class="link-more" href="{{ Site::route('packages') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        <div class="grid-cards">@foreach ($packages as $item)@include('site.partials.trip-card', ['item' => $item])@endforeach</div>
    </div>
</section>
@endif

{{-- Reviews (30 Oct): only real reviews entered in the CRM; otherwise a link to Google. --}}
<section class="sec reviews-sec">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.x.reviews_eyebrow') }}</span><h2 data-split>{{ __('site.x.reviews_title') }}</h2>@if (count($reviews))<p>{{ __('site.x.reviews_text') }}</p>@endif</div>
            @if (Site::mapUrl())<a class="link-more" href="{{ Site::mapUrl() }}" target="_blank" rel="noopener">{{ __('site.x.reviews_google') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a>@endif
        </div>
        @if (count($reviews))
            <div class="rev-track" data-revs>
                @foreach ($reviews as $rv)
                    <figure class="rev rv">
                        <div class="rev-stars" aria-label="{{ $rv['rating'] }}/5">@for ($i = 1; $i <= 5; $i++)<svg class="i i-s {{ $i <= $rv['rating'] ? 'on' : '' }}"><use href="#i-star"/></svg>@endfor</div>
                        <blockquote>“{{ $rv['text'] }}”</blockquote>
                        <figcaption><span class="rev-av">{{ mb_strtoupper(mb_substr($rv['name'], 0, 1)) }}</span><span><b>{{ $rv['name'] }}</b>@if ($rv['source'])<small>{{ __('site.x.reviews_on', ['source' => $rv['source']]) }}</small>@endif</span></figcaption>
                    </figure>
                @endforeach
            </div>
        @elseif (Site::mapUrl())
            <a class="rev-empty rv" href="{{ Site::mapUrl() }}" target="_blank" rel="noopener">
                <span class="rev-g">G</span>
                <span><span class="rev-stars">@for ($i = 1; $i <= 5; $i++)<svg class="i on"><use href="#i-star"/></svg>@endfor</span><b>{{ __('site.x.reviews_empty') }}</b></span>
                <span class="btn btn-red">{{ __('site.x.reviews_google') }}</span>
            </a>
        @endif
    </div>
</section>

@if (count($brandList))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv">
            <div><span class="eyebrow">{{ __('site.brands.eyebrow') }}</span><h2>{{ __('site.home.brands_title') }}</h2><p>{{ __('site.brands.lead') }}</p></div>
            <a class="link-more" href="{{ Site::route('brands') }}">{{ __('site.brands.all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a>
        </div>
        @include('site.partials.brand-tiles', ['brands' => $brandList])
    </div>
</section>
@endif

@if (count($posts))
<section class="sec">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.x.blog_eyebrow') }}</span><h2 data-split>{{ __('site.x.blog_title') }}</h2></div><a class="link-more" href="{{ Site::route('blog') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        <div class="post-grid">@foreach ($posts as $post)@include('site.partials.post-card', ['post' => $post])@endforeach</div>
    </div>
</section>
@endif

<section class="sec">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.home.social_eyebrow') }}</span><h2>{{ __('site.home.social_title') }}</h2><p>{{ __('site.home.social_text') }}</p></div></div>
        <div class="social-grid">
            @php $netNames = ['instagram' => ['Instagram', 'ig'], 'facebook' => ['Facebook', 'fb'], 'tiktok' => ['TikTok', 'tt'], 'snapchat' => ['Snapchat', 'sc']]; @endphp
            @foreach (Site::socials() as $net => $url)
                <a class="social rv" href="{{ $url }}" target="_blank" rel="noopener"><span class="ico {{ $netNames[$net][1] }}"><svg class="i i-l"><use href="#i-{{ $net }}"/></svg></span><span><b>{{ $netNames[$net][0] }}</b><small>@redprimerentals</small></span></a>
            @endforeach
            @if (Site::mapUrl())
                <a class="social rv" href="{{ Site::mapUrl() }}" target="_blank" rel="noopener"><span class="ico" style="background:linear-gradient(135deg,#d7141a,#d4a62a)"><svg class="i i-l"><use href="#i-star"/></svg></span><span><b>{{ __('site.home.reviews_title') }}</b><small>{{ __('site.home.reviews_cta') }}</small></span></a>
            @endif
        </div>
    </div>
</section>

<section class="sec">
    <div class="wrap" style="max-width:920px">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.home.faq_eyebrow') }}</span><h2>{{ __('site.home.faq_title') }}</h2></div><a class="link-more" href="{{ Site::route('faq') }}">{{ __('site.home.faq_more') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        @include('site.partials.faq', ['faqs' => array_slice(__('site.faq.items'), 0, 5)])
    </div>
</section>

@include('site.partials.cta')
@endsection
