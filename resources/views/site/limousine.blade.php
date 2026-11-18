@extends('site.layout')
@php
    use App\Support\Site\Site;
    $today = now()->toDateString();
    $times = collect(range(0, 47))->map(fn ($i) => sprintf('%02d:%02d', intdiv($i, 2), $i % 2 ? 30 : 0));
    $pick = collect($services)->firstWhere('slug', request('service')) ?? $services[0];
    // 11 Nov (Bilal): priced by distance only. Car first, then pickup and destination (typed, pin on the map or "my location").
    $distance = \App\Support\Transfers\RideFlow::distanceOnly();
    $mapsOn = \App\Support\Maps\GoogleMaps::ready();
    $mapsKey = $mapsOn ? \App\Support\Maps\GoogleMaps::browserKey() : null;
    $wa = Site::whatsapp(__('site.wa.general'));
@endphp

@section('content')
<section class="phead">
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ __('site.nav.limousine') }}</span></nav>
        <span class="eyebrow mt-2">{{ __('site.limo.eyebrow') }}</span>
        <h1 data-split>{{ __('site.limo.title') }}</h1>
        <p>{{ $distance ? __('site.limo.lead_distance') : __('site.limo.lead') }}</p>
    </div>
</section>

<section class="sec" style="padding-top:36px">
    <form class="wrap detail" style="padding-top:0" data-quote="transfer" data-item="{{ __('site.nav.limousine') }}" data-distance="{{ $distance ? 1 : 0 }}" data-maps-key="{{ $mapsKey }}" data-t-denied="{{ __('site.limo.loc_denied') }}" data-t-finding="{{ __('site.limo.map_finding') }}" data-t-pickup="{{ __('site.limo.map_title_pickup') }}" data-t-dropoff="{{ __('site.limo.map_title_dropoff') }}" novalidate>
        <div class="grid" style="gap:24px">
            @if ($distance)
            <div>
                <h2 style="font-size:24px;margin-bottom:14px">{{ $distance ? __('site.limo.step_car') : __('site.limo.vehicle') }}</h2>
                <div class="vcats">
                    @foreach ($categories as $i => $c)
                        <label class="vcat"><input type="radio" name="product_slug" value="{{ $c['slug'] }}" @checked($i === 0)>
                            <span class="ico"><svg class="i"><use href="#i-car"/></svg></span>
                            <b>{{ $c['name'] }}</b>
                            <span class="vcat-price" data-class-price="{{ $c['slug'] }}" aria-live="polite"></span>
                            @if ($c['passengers'] || $c['luggage'])<small>{{ __('site.limo.up_to', ['p' => $c['passengers'] ?? '—', 'l' => $c['luggage'] ?? '—']) }}</small>@endif
                            @if (! empty($c['description']))<small>{{ \Illuminate\Support\Str::limit($c['description'], 90) }}</small>@endif
                            @if (! empty($c['from_price']['amount']))<small class="gold" style="font-weight:600">{{ __('site.limo.min_fare', ['price' => Site::money($c['from_price']['amount'])]) }}</small>@endif
                        </label>
                    @endforeach
                </div>
            </div>

            @endif
            <div class="panel">
                @if ($distance)<h2 style="font-size:24px;margin-bottom:14px">{{ __('site.limo.step_route') }}</h2>@else
                <div class="field"><span class="label">{{ __('site.limo.service') }}</span>
                    <div class="chips" role="radiogroup">
                        @foreach ($services as $svc)
                            <label class="chip"><input type="radio" name="service" value="{{ $svc['slug'] }}" data-needs='@json($svc['needs'])' data-return="{{ $svc['return_journey'] ? 1 : 0 }}" @checked($svc['slug'] === $pick['slug'])>{{ $svc['name'] }}</label>
                        @endforeach
                    </div>
                    @foreach ($services as $svc)@if (! empty($svc['description']))<p class="muted small" data-svc-desc="{{ $svc['slug'] }}" @if ($svc['slug'] !== $pick['slug']) hidden @endif>{{ $svc['description'] }}</p>@endif @endforeach
                </div>
                @endif
                @php $spots = \App\Support\Transfers\StarterCatalog::SPOTS; @endphp<div class="field"><label for="l-pickup">{{ __('site.limo.pickup') }}</label><input class="input" id="l-pickup" name="pickup" placeholder="{{ __('site.limo.pickup_ph') }}" maxlength="255" autocomplete="street-address"><div class="ac-list" data-ac-list hidden></div><input type="hidden" name="pickup_ll">@if ($mapsOn)<div class="loc-tools"><button type="button" class="loc-btn" data-my-location="pickup"><svg class="i i-s"><use href="#i-pin"/></svg>{{ __('site.limo.my_location') }}</button>@if ($mapsKey)<button type="button" class="loc-btn" data-on-map="pickup"><svg class="i i-s"><use href="#i-pin"/></svg>{{ __('site.limo.on_map') }}</button>@endif</div>@endif<div class="spots" data-spots>@foreach (array_slice($spots, 0, 5) as $sp)<button type="button" class="spot" data-spot="{{ $sp }}">{{ \Illuminate\Support\Str::before($sp, ' (') }}</button>@endforeach</div></div>
                <div class="field" data-need="dropoff"><label for="l-dropoff">{{ __('site.limo.dropoff') }}</label><input class="input" id="l-dropoff" name="dropoff" placeholder="{{ __('site.limo.dropoff_ph') }}" maxlength="255"><div class="ac-list" data-ac-list hidden></div><input type="hidden" name="dropoff_ll">@if ($mapsOn)<div class="loc-tools">@if ($mapsKey)<button type="button" class="loc-btn" data-on-map="dropoff"><svg class="i i-s"><use href="#i-pin"/></svg>{{ __('site.limo.on_map') }}</button>@endif</div>@endif<div class="spots" data-spots>@foreach ($spots as $sp)<button type="button" class="spot" data-spot="{{ $sp }}">{{ \Illuminate\Support\Str::before($sp, ' (') }}</button>@endforeach</div></div>
                <div class="row2">
                    <div class="field"><span class="label">{{ __('site.limo.date') }}</span><div class="dt">
                        <input class="input" type="date" name="start_d" min="{{ $today }}" aria-label="{{ __('site.limo.date') }}">
                        <select class="select" name="start_t" aria-label="{{ __('site.limo.time') }}">@foreach ($times as $t)<option @selected($t === '10:00')>{{ $t }}</option>@endforeach</select>
                    </div></div>
                    @if (! $distance)<div class="field" data-need="end_at"><span class="label">{{ __('site.limo.until') }}</span><div class="dt">
                        <input class="input" type="date" name="end_d" min="{{ $today }}" aria-label="{{ __('site.limo.until') }}">
                        <select class="select" name="end_t" aria-label="{{ __('site.limo.time') }}">@foreach ($times as $t)<option @selected($t === '15:00')>{{ $t }}</option>@endforeach</select>
                    </div></div>
                    <div class="field" data-need="flight_number"><label for="l-flight">{{ __('site.limo.flight') }}</label><input class="input" id="l-flight" name="flight_number" placeholder="{{ __('site.limo.flight_ph') }}" maxlength="20" dir="ltr"></div>@endif
                </div>
                <div class="row2">
                    <div class="field"><label for="l-pax">{{ __('site.limo.passengers') }}</label><div class="stepper" data-stepper><button type="button" data-step="-1" aria-label="-">−</button><input id="l-pax" type="number" name="passengers" min="1" max="60" value="2"><button type="button" data-step="1" aria-label="+">+</button></div></div>
                    <div class="field"><label for="l-bags">{{ __('site.limo.luggage') }}</label><div class="stepper" data-stepper><button type="button" data-step="-1" aria-label="-">−</button><input id="l-bags" type="number" name="luggage" min="0" max="60" value="2"><button type="button" data-step="1" aria-label="+">+</button></div></div>
                </div>
                @if (! $distance)<div data-need="return">
                    <label class="switch"><span>{{ __('site.limo.return') }}</span><input type="checkbox" name="return" value="1"><span class="knob"></span></label>
                    <div class="field mt-2" data-show-if="return" hidden><span class="label">{{ __('site.limo.return_at') }}</span><div class="dt">
                        <input class="input" type="date" name="return_d" min="{{ $today }}" aria-label="{{ __('site.limo.return_at') }}">
                        <select class="select" name="return_t" aria-label="{{ __('site.limo.time') }}">@foreach ($times as $t)<option @selected($t === '18:00')>{{ $t }}</option>@endforeach</select>
                    </div></div>
                </div>@endif
            </div>

            @if (! $distance)
            <div>
                <h2 style="font-size:24px;margin-bottom:14px">{{ __('site.limo.vehicle') }}</h2>
                <div class="vcats">
                    @foreach ($categories as $i => $c)
                        <label class="vcat"><input type="radio" name="product_slug" value="{{ $c['slug'] }}" @checked($i === 0)>
                            <span class="ico"><svg class="i"><use href="#i-car"/></svg></span>
                            <b>{{ $c['name'] }}</b>
                            <span class="vcat-price" data-class-price="{{ $c['slug'] }}" aria-live="polite"></span>
                            @if ($c['passengers'] || $c['luggage'])<small>{{ __('site.limo.up_to', ['p' => $c['passengers'] ?? '—', 'l' => $c['luggage'] ?? '—']) }}</small>@endif
                            @if (! empty($c['description']))<small>{{ \Illuminate\Support\Str::limit($c['description'], 90) }}</small>@endif
                            @if (! empty($c['from_price']['amount']))<small class="gold" style="font-weight:600">{{ __('site.limo.min_fare', ['price' => Site::money($c['from_price']['amount'])]) }}</small>@endif
                        </label>
                    @endforeach
                </div>
            </div>

            @endif
            <div class="features">
                @foreach (__('site.limo.features') as $ft)
                    <div class="feature"><div class="ico"><svg class="i i-l"><use href="#i-check-c"/></svg></div><h3>{{ $ft['title'] }}</h3><p>{{ $ft['text'] }}</p></div>
                @endforeach
            </div>
        </div>

        <aside id="book">
            <div class="bk">
                <div class="bk-head"><small>{{ __('site.limo.eyebrow') }}</small><div class="from" style="font-size:24px">{{ __('site.quote.estimate') }}</div></div>
                <div class="bk-body">
                    <div class="bk-step on" data-step="quote">
                        <div class="route-map" data-map hidden></div>
                        <div class="route-box" data-route hidden><div><small>{{ __('site.limo.distance') }}</small><b data-route-km>—</b></div><div><small>{{ __('site.limo.time_est') }}</small><b data-route-min>—</b></div></div>
                        <div class="est" data-est aria-live="polite"><p class="muted small">{{ __('site.quote.pick_dates') }}</p></div>
                                                <button type="button" class="btn btn-red btn-block btn-l" data-next>{{ \App\Support\Transfers\RideFlow::agentConfirms() ? __('site.limo.request_ride') : __('site.booking.book_now') }} <svg class="i flip"><use href="#i-arrow"/></svg></button>
                        @if ($wa)<a class="btn btn-ghost btn-block" href="{{ $wa }}" target="_blank" rel="noopener"><svg class="i" style="color:#25d366"><use href="#i-whatsapp"/></svg>{{ __('site.common.ask_whatsapp') }}</a>@endif
                    </div>
                    @include('site.partials.request-steps', ['p' => 'lm'] + (\App\Support\Transfers\RideFlow::agentConfirms() ? ['sendLabel' => __('site.limo.request_ride'), 'sendNote' => __('site.limo.estimate_note')] : []))
                </div>
            </div>
        </aside>
    </form>
</section>
{{-- 11 Nov: pick a point like in a ride app: the pin stays in the middle, the map moves under it. --}}
<div class="mapick" data-mapick hidden role="dialog" aria-modal="true" aria-label="{{ __('site.limo.on_map') }}">
    <div class="mapick-box">
        <div class="mapick-head"><b data-mapick-title></b><button type="button" class="mapick-x" data-mapick-close aria-label="{{ __('site.quote.back') }}">×</button></div>
        <div class="mapick-search"><input class="input" data-mapick-search placeholder="{{ __('site.limo.map_search') }}" autocomplete="off"><div class="ac-list" data-mapick-list hidden></div></div>
        <div class="mapick-map"><div data-mapick-canvas></div><span class="mapick-pin" aria-hidden="true"></span>
            <button type="button" class="mapick-me" data-mapick-me title="{{ __('site.limo.my_location') }}"><svg class="i"><use href="#i-pin"/></svg></button></div>
        <div class="mapick-foot"><p data-mapick-addr class="muted small">{{ __('site.limo.map_finding') }}</p>
            <button type="button" class="btn btn-red btn-block" data-mapick-ok>{{ __('site.limo.map_confirm') }}</button></div>
    </div>
</div>
@include('site.partials.cta')
@endsection
