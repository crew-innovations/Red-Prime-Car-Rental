@extends('site.layout')
@section('body_class', 'has-mbar')
@php
    use App\Support\Site\Site;
    use App\Support\Site\Catalog;
    $p = $car['prices'] ?? [];
    $r = $car['rules'] ?? [];
    $from = Catalog::carFrom($car);
    $today = now()->toDateString();
    $times = collect(range(0, 47))->map(fn ($i) => sprintf('%02d:%02d', intdiv($i, 2), $i % 2 ? 30 : 0));
    $modes = array_filter(['self_drive' => ! empty($car['self_drive']), 'with_driver' => ! empty($car['with_driver'])]);
    $defaultMode = request('mode') === 'with_driver' && isset($modes['with_driver']) ? 'with_driver' : array_key_first($modes) ?? 'self_drive';
    $tags = collect([$car['brand'] ?? null, $car['body'] ?? null, $car['year'] ?? null])->filter()->implode(' · ');
    $badges = (! empty($car['badge']) ? '<span class="badge badge-gold">'.e($car['badge']).'</span>' : '').(! empty($car['with_driver']) ? '<span class="badge badge-dark">'.e(__('site.common.chauffeur_available')).'</span>' : '');
    $specRows = array_filter([
        __('site.car.year') => $car['year'] ?? null,
        __('site.car.body') => $car['body'] ?? null,
        __('site.car.transmission') => isset($car['transmission']) ? ucfirst($car['transmission']) : null,
        __('site.car.fuel') => isset($car['fuel']) ? ucfirst($car['fuel']) : null,
        __('site.car.power') => ! empty($car['horsepower']) ? __('site.common.hp', ['n' => $car['horsepower']]) : null,
        __('site.car.seats') => $car['seats'] ?? null,
        __('site.car.doors') => $car['doors'] ?? null,
        __('site.car.luggage') => $car['luggage'] ?? null,
        __('site.car.colour') => isset($car['colour']) ? ucfirst($car['colour']) : null,
    ]);
    foreach ($car['specifications'] ?? [] as $s) { $specRows[$s['label']] = $s['value']; }
    $ruleRows = array_filter([
        __('site.car.min_rental_days') => ! empty($r['min_rental_days']) ? __('site.common.days', ['n' => $r['min_rental_days']]) : null,
        __('site.car.daily_km_limit') => ! empty($r['daily_km_limit']) ? __('site.car.km', ['n' => number_format($r['daily_km_limit'])]) : null,
        __('site.car.extra_km_charge') => ! empty($r['extra_km_charge']) ? Site::money($r['extra_km_charge'], true) : null,
        __('site.car.min_driver_age') => ! empty($r['min_driver_age']) ? __('site.car.years', ['n' => $r['min_driver_age']]) : null,
        __('site.car.delivery') => ! empty($r['delivery_available']) ? __('site.car.delivery_yes').(! empty($r['delivery_fee']) ? ' · '.Site::money($r['delivery_fee']) : '') : null,
        __('site.car.insurance') => isset($r['insurance']) ? ucfirst(str_replace('_', ' ', $r['insurance'])) : null,
        __('site.common.deposit') => ! empty($car['deposit']) ? Site::money($car['deposit']).' ('.__('site.common.refundable').')' : null,
    ]);
    $priceRows = array_filter([
        'daily' => $p['daily'] ?? null, 'weekly' => $p['weekly'] ?? null, 'monthly' => $p['monthly'] ?? null, 'hourly' => $p['hourly'] ?? null,
        'chauffeur_daily' => $p['chauffeur_daily'] ?? null, 'chauffeur_hourly' => $p['chauffeur_hourly'] ?? null,
    ]);
    $priceUnit = ['daily' => 'per_day', 'weekly' => 'per_week', 'monthly' => 'per_month', 'hourly' => 'per_hour', 'chauffeur_daily' => 'per_day', 'chauffeur_hourly' => 'per_hour'];
@endphp

@section('content')
<div class="wrap" style="padding-top:22px">
    <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><a href="{{ Site::route('cars') }}">{{ __('site.nav.cars') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ $car['name'] }}</span></nav>
</div>

<div class="wrap detail">
    <div>
        @include('site.partials.gallery', ['images' => $car['images'] ?? [], 'name' => $car['name'], 'kind' => 'car', 'badges' => $badges])

        <div class="d-head">
            <span class="card-tag">{{ $tags }}</span>
            <h1>{{ $car['name'] }}</h1>
            @if (! empty($car['summary']))<p class="muted" style="font-size:17px">{{ $car['summary'] }}</p>@endif
        </div>

        <div class="keyspecs">
            @if (! empty($car['horsepower']))<div class="keyspec"><svg class="i"><use href="#i-gauge"/></svg><small>{{ __('site.car.power') }}</small><b>{{ __('site.common.hp', ['n' => $car['horsepower']]) }}</b></div>@endif
            @if (! empty($car['seats']))<div class="keyspec"><svg class="i"><use href="#i-users"/></svg><small>{{ __('site.car.seats') }}</small><b>{{ $car['seats'] }}</b></div>@endif
            @if (! empty($car['transmission']))<div class="keyspec"><svg class="i"><use href="#i-cog"/></svg><small>{{ __('site.car.transmission') }}</small><b>{{ ucfirst($car['transmission']) }}</b></div>@endif
            @if (! empty($car['fuel']))<div class="keyspec"><svg class="i"><use href="#i-fuel"/></svg><small>{{ __('site.car.fuel') }}</small><b>{{ ucfirst($car['fuel']) }}</b></div>@endif
            @if (! empty($car['deposit']))<div class="keyspec"><svg class="i"><use href="#i-shield"/></svg><small>{{ __('site.common.deposit') }}</small><b>{{ Site::money($car['deposit']) }}</b></div>@endif
        </div>

        <nav class="d-tabs" aria-label="{{ $car['name'] }}" data-spy>
            @if (! empty($car['description_html']))<a href="#overview">{{ __('site.car.overview') }}</a>@endif
            @if ($priceRows)<a href="#prices">{{ __('site.car.prices') }}</a>@endif
            @if (! empty($car['features']))<a href="#features">{{ __('site.car.features') }}</a>@endif
            @if ($specRows)<a href="#specs">{{ __('site.car.specs') }}</a>@endif
            @if ($ruleRows)<a href="#rules">{{ __('site.car.rules') }}</a>@endif
            @if (! empty($car['add_ons']))<a href="#addons">{{ __('site.car.addons') }}</a>@endif
        </nav>

        @if (! empty($car['description_html']))
            <section class="block" id="overview"><h2>{{ __('site.car.overview') }}</h2><div class="prose">{!! Site::safeHtml($car['description_html']) !!}</div></section>
        @endif

        @if ($priceRows)
        <section class="block" id="prices"><h2>{{ __('site.car.prices') }}</h2>
            <table class="table">
                <tbody>@foreach ($priceRows as $k => $v)<tr><th scope="row">{{ __('site.car.'.$k) }}</th><td>{{ Site::money($v) }} <span class="muted" style="font-weight:400">{{ __('site.common.'.$priceUnit[$k]) }}</span></td></tr>@endforeach</tbody>
            </table>
        </section>
        @endif

        @if (! empty($car['features']))
        <section class="block" id="features"><h2>{{ __('site.car.features') }}</h2>
            <ul class="feat-list">@foreach ($car['features'] as $ft)<li><svg class="i"><use href="#i-check-c"/></svg>{{ $ft }}</li>@endforeach</ul>
        </section>
        @endif

        @if ($specRows)
        <section class="block" id="specs"><h2>{{ __('site.car.specs') }}</h2>
            <table class="table"><tbody>@foreach ($specRows as $k => $v)<tr><th scope="row">{{ $k }}</th><td>{{ $v }}</td></tr>@endforeach</tbody></table>
        </section>
        @endif

        @if ($ruleRows)
        <section class="block" id="rules"><h2>{{ __('site.car.rules') }}</h2>
            <table class="table"><tbody>@foreach ($ruleRows as $k => $v)<tr><th scope="row">{{ $k }}</th><td>{{ $v }}</td></tr>@endforeach</tbody></table>
        </section>
        @endif

        @if (! empty($car['add_ons']))
        <section class="block" id="addons"><h2>{{ __('site.car.addons') }}</h2>
            <ul class="feat-list">@foreach ($car['add_ons'] as $a)<li><svg class="i"><use href="#i-plus"/></svg><span style="flex:1">{{ $a['name'] }}</span>@if (! empty($a['price']))<b class="gold">{{ Site::money($a['price']) }}</b>@endif</li>@endforeach</ul>
        </section>
        @endif

        @if (! empty($car['terms']))
        <section class="block" id="terms"><h2>{{ __('site.car.terms') }}</h2><div class="prose" style="white-space:pre-line">{{ strip_tags($car['terms']) }}</div></section>
        @endif
    </div>

    <aside id="book">
        <form class="bk" data-quote="car" data-slug="{{ $car['slug'] }}" data-item="{{ $car['name'] }}" novalidate>
            <div class="bk-head">
                <small>{{ __('site.common.from') }}</small>
                <div class="from" data-from-label>@if ($from){{ Site::money($from) }} <span>{{ __('site.common.per_day') }}</span>@else{{ __('site.common.price_on_request') }}@endif</div>
            </div>
            <div class="bk-body">
                <div class="bk-step on" data-step="quote">
                    @if (count($modes) > 1)
                        <div class="field"><span class="label">{{ __('site.quote.mode') }}</span>
                            <div class="seg">
                                <label><input type="radio" name="drive_mode" value="self_drive" @checked($defaultMode === 'self_drive') data-from="{{ isset($p['daily']) ? Site::money($p['daily']) : '' }}">{{ __('site.common.self_drive') }}</label>
                                <label><input type="radio" name="drive_mode" value="with_driver" @checked($defaultMode === 'with_driver') data-from="{{ isset($p['chauffeur_daily']) ? Site::money($p['chauffeur_daily']) : (isset($p['daily']) ? Site::money($p['daily']) : '') }}">{{ __('site.common.with_chauffeur') }}</label>
                            </div>
                        </div>
                    @else
                        <input type="hidden" name="drive_mode" value="{{ $defaultMode }}">
                    @endif
                    <div class="field"><span class="label">{{ __('site.quote.pickup') }}</span><div class="dt">
                        <input class="input" type="date" name="start_d" min="{{ $today }}" value="{{ request('from') ? substr(request('from'), 0, 10) : '' }}" aria-label="{{ __('site.quote.pickup') }}">
                        <select class="select" name="start_t" aria-label="{{ __('site.home.time') }}">@foreach ($times as $t)<option @selected($t === (request('from') ? substr(request('from'), 11, 5) : '10:00'))>{{ $t }}</option>@endforeach</select>
                    </div></div>
                    <div class="field"><span class="label">{{ __('site.quote.return') }}</span><div class="dt">
                        <input class="input" type="date" name="end_d" min="{{ $today }}" value="{{ request('to') ? substr(request('to'), 0, 10) : '' }}" aria-label="{{ __('site.quote.return') }}">
                        <select class="select" name="end_t" aria-label="{{ __('site.home.time') }}">@foreach ($times as $t)<option @selected($t === (request('to') ? substr(request('to'), 11, 5) : '10:00'))>{{ $t }}</option>@endforeach</select>
                    </div></div>
                    @if (! empty($r['delivery_available']))
                        <label class="switch"><span>{{ __('site.quote.delivery') }}</span><input type="checkbox" name="delivery" value="1"><span class="knob"></span></label>
                        <label class="switch"><span>{{ __('site.quote.collection') }}</span><input type="checkbox" name="collection" value="1"><span class="knob"></span></label>
                        <div class="field" data-show-if="delivery" hidden><label for="car-addr">{{ __('site.quote.address') }}</label><input class="input" id="car-addr" name="address" placeholder="{{ __('site.quote.address_ph') }}" maxlength="200"></div>
                    @endif
                    <div class="field" data-extras-wrap hidden><span class="label">{{ __('site.quote.extras') }}</span><div class="extras" data-extras></div></div>
                    <div class="est" data-est aria-live="polite"><p class="muted small">{{ __('site.quote.pick_dates') }}</p></div>
                    <button type="button" class="btn btn-red btn-block btn-l" data-next>{{ __('site.booking.book_now') }} <svg class="i flip"><use href="#i-arrow"/></svg></button>
                    @if ($wa = Site::whatsapp(__('site.wa.hello', ['item' => $car['name']])))
                        <a class="btn btn-ghost btn-block" href="{{ $wa }}" data-wa-dates target="_blank" rel="noopener"><svg class="i" style="color:#25d366"><use href="#i-whatsapp"/></svg>{{ __('site.common.ask_whatsapp') }}</a>
                    @endif
                    <div class="bk-trust"><span><svg class="i"><use href="#i-shield"/></svg>{{ __('site.common.deposit') }}: {{ ! empty($car['deposit']) ? Site::money($car['deposit']) : '—' }}</span><span><svg class="i"><use href="#i-check-c"/></svg>{{ __('site.quote.on_request') }}</span></div>
                </div>
                @include('site.partials.request-steps', ['p' => 'car'])
            </div>
        </form>
    </aside>
</div>

@if (count($related))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.home.fleet_eyebrow') }}</span><h2>{{ __('site.car.related') }}</h2></div><a class="link-more" href="{{ Site::route('cars') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        <div class="grid-cards">@foreach ($related as $c)@include('site.partials.car-card', ['car' => $c])@endforeach</div>
    </div>
</section>
@endif

<div class="mbar">
    <div class="price"><small>{{ __('site.quote.mobile_from') }}</small><b>@if ($from){{ Site::money($from) }} <span>{{ __('site.common.per_day') }}</span>@else{{ __('site.common.price_on_request') }}@endif</b></div>
    <div class="flex gap-1">
        @if ($wa)<a class="icon-btn wa" href="{{ $wa }}" target="_blank" rel="noopener" aria-label="{{ __('site.common.ask_whatsapp') }}"><svg class="i"><use href="#i-whatsapp"/></svg></a>@endif
        <a class="btn btn-red" href="#book">{{ __('site.common.book_now') }}</a>
    </div>
</div>
@include('site.partials.assistant', ['slug' => $car['slug'] ?? null])
@endsection
