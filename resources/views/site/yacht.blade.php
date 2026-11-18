@extends('site.layout')
@section('body_class', 'has-mbar')
@php
    use App\Support\Site\Site;
    $p = $yacht['prices'] ?? [];
    $today = now()->toDateString();
    $times = collect(range(12, 47))->map(fn ($i) => sprintf('%02d:%02d', intdiv($i, 2), $i % 2 ? 30 : 0));
    $minH = max(1, (int) ($yacht['minimum_hours'] ?? 1));
    $halfH = (int) \App\Models\Setting::read('yacht_half_day_hours', 4);
    $fullH = (int) \App\Models\Setting::read('yacht_full_day_from_hours', 8);
    $durations = collect(range($minH, max($minH, 12)))->values();
    $defaultH = $durations->contains(3) ? 3 : $durations->first();
    $from = $p['hourly'] ?? (! empty($p) ? min($p) : null);
    $badges = (! empty($yacht['badge']) ? '<span class="badge badge-gold">'.e($yacht['badge']).'</span>' : '').(! empty($yacht['captain_included']) ? '<span class="badge badge-dark">'.e(__('site.common.captain_included')).'</span>' : '');
    $specRows = array_filter([
        __('site.yachts.length') => ! empty($yacht['length_ft']) ? __('site.common.ft', ['n' => (float) $yacht['length_ft']]) : null,
        __('site.yachts.capacity') => $yacht['guests'] ?? null,
        __('site.yachts.cabins') => $yacht['cabins'] ?? null,
        __('site.yachts.bathrooms') => $yacht['bathrooms'] ?? null,
        __('site.yachts.crew') => $yacht['crew'] ?? null,
        __('site.yachts.type') => isset($yacht['yacht_type']) ? ucfirst(str_replace('_', ' ', $yacht['yacht_type'])) : null,
        __('site.yachts.make') => trim(($yacht['manufacturer'] ?? '').' '.($yacht['model'] ?? '')) ?: null,
        __('site.yachts.built') => $yacht['year_built'] ?? null,
        __('site.yachts.marina') => $yacht['marina'] ?? null,
        __('site.yachts.fuel_included') => ! empty($yacht['fuel_included']) ? __('site.common.yes') : null,
    ]);
    foreach ($yacht['specifications'] ?? [] as $s) { $specRows[$s['label']] = $s['value']; }
    $wa = Site::whatsapp(__('site.wa.hello', ['item' => $yacht['name']]));
@endphp

@section('content')
<div class="wrap" style="padding-top:22px">
    <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><a href="{{ Site::route('yachts') }}">{{ __('site.nav.yachts') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ $yacht['name'] }}</span></nav>
</div>

<div class="wrap detail">
    <div>
        @include('site.partials.gallery', ['images' => $yacht['images'] ?? [], 'name' => $yacht['name'], 'kind' => 'yacht', 'badges' => $badges])
        <div class="d-head">
            <span class="card-tag">{{ collect([$yacht['marina'] ?? null, $yacht['manufacturer'] ?? null])->filter()->implode(' · ') }}</span>
            <h1>{{ $yacht['name'] }}</h1>
            @if (! empty($yacht['summary']))<p class="muted" style="font-size:17px">{{ $yacht['summary'] }}</p>@endif
        </div>
        <div class="keyspecs">
            @if (! empty($yacht['length_ft']))<div class="keyspec"><svg class="i"><use href="#i-yacht"/></svg><small>{{ __('site.yachts.length') }}</small><b>{{ __('site.common.ft', ['n' => (float) $yacht['length_ft']]) }}</b></div>@endif
            @if (! empty($yacht['guests']))<div class="keyspec"><svg class="i"><use href="#i-users"/></svg><small>{{ __('site.yachts.capacity') }}</small><b>{{ $yacht['guests'] }}</b></div>@endif
            @if (! empty($yacht['cabins']))<div class="keyspec"><svg class="i"><use href="#i-door"/></svg><small>{{ __('site.yachts.cabins') }}</small><b>{{ $yacht['cabins'] }}</b></div>@endif
            @if (! empty($yacht['minimum_hours']))<div class="keyspec"><svg class="i"><use href="#i-clock"/></svg><small>{{ __('site.quote.duration') }}</small><b>{{ __('site.yachts.min_hours', ['n' => $yacht['minimum_hours']]) }}</b></div>@endif
            @if (! empty($yacht['marina']))<div class="keyspec"><svg class="i"><use href="#i-pin"/></svg><small>{{ __('site.yachts.marina') }}</small><b>{{ $yacht['marina'] }}</b></div>@endif
        </div>

        @if ($p)
        <section class="block" id="prices"><h2>{{ __('site.car.prices') }}</h2>
            <div class="price-chips" style="margin-top:0">
                @foreach (['hourly', 'half_day', 'sunset', 'full_day'] as $k)
                    @if (! empty($p[$k]))<div class="price-chip"><small>{{ __('site.yachts.'.$k) }}</small><b>{{ Site::money($p[$k]) }}</b></div>@endif
                @endforeach
            </div>
        </section>
        @endif
        @if (! empty($yacht['description_html']))
            <section class="block" id="overview"><h2>{{ __('site.car.overview') }}</h2><div class="prose">{!! Site::safeHtml($yacht['description_html']) !!}</div></section>
        @endif
        @if (! empty($yacht['amenities']))
            <section class="block"><h2>{{ __('site.yachts.amenities') }}</h2><ul class="feat-list">@foreach ($yacht['amenities'] as $a)<li><svg class="i"><use href="#i-check-c"/></svg>{{ $a }}</li>@endforeach</ul></section>
        @endif
        @if ($specRows)
            <section class="block" id="specs"><h2>{{ __('site.car.specs') }}</h2><table class="table"><tbody>@foreach ($specRows as $k => $v)<tr><th scope="row">{{ $k }}</th><td>{{ $v }}</td></tr>@endforeach</tbody></table></section>
        @endif
        @if (! empty($yacht['add_ons']))
            <section class="block"><h2>{{ __('site.car.addons') }}</h2><ul class="feat-list">@foreach ($yacht['add_ons'] as $a)<li><svg class="i"><use href="#i-plus"/></svg><span style="flex:1">{{ $a['name'] }}</span>@if (! empty($a['price']))<b class="gold">{{ Site::money($a['price']) }}</b>@endif</li>@endforeach</ul></section>
        @endif
        @if (! empty($yacht['mandatory_charges']))
            <section class="block"><h2>{{ __('site.yachts.mandatory') }}</h2><table class="table"><tbody>@foreach ($yacht['mandatory_charges'] as $c)<tr><th scope="row">{{ $c['name'] }}</th><td>{{ isset($c['amount']) ? Site::money($c['amount']) : '' }}</td></tr>@endforeach</tbody></table></section>
        @endif
        @if (! empty($yacht['restrictions']))
            <section class="block"><h2>{{ __('site.yachts.restrictions') }}</h2><div class="prose" style="white-space:pre-line">{{ strip_tags($yacht['restrictions']) }}</div></section>
        @endif
        @if (! empty($yacht['terms']))
            <section class="block"><h2>{{ __('site.car.terms') }}</h2><div class="prose" style="white-space:pre-line">{{ strip_tags($yacht['terms']) }}</div></section>
        @endif
    </div>

    <aside id="book">
        <form class="bk" data-quote="yacht" data-slug="{{ $yacht['slug'] }}" data-item="{{ $yacht['name'] }}" novalidate>
            <div class="bk-head"><small>{{ __('site.common.from') }}</small><div class="from">@if ($from){{ Site::money($from) }} <span>{{ ! empty($p['hourly']) ? __('site.common.per_hour') : '' }}</span>@else{{ __('site.common.price_on_request') }}@endif</div></div>
            <div class="bk-body">
                <div class="bk-step on" data-step="quote">
                    <div class="field"><span class="label">{{ __('site.quote.date') }}</span><div class="dt">
                        <input class="input" type="date" name="start_d" min="{{ $today }}" aria-label="{{ __('site.quote.date') }}">
                        <select class="select" name="start_t" aria-label="{{ __('site.quote.start') }}">@foreach ($times as $t)<option @selected($t === '16:00')>{{ $t }}</option>@endforeach</select>
                    </div></div>
                    <div class="field"><label for="yt-hours">{{ __('site.quote.duration') }}</label>
                        <select class="select" id="yt-hours" name="hours">
                            @foreach ($durations as $h)<option value="{{ $h }}" @selected($h === $defaultH)>{{ __('site.quote.hours', ['n' => $h]) }}@if ($h === $halfH && ! empty($p['half_day'])) · {{ __('site.yachts.half_day') }}@elseif ($h === $fullH && ! empty($p['full_day'])) · {{ __('site.yachts.full_day') }}@endif</option>@endforeach
                        </select>
                    </div>
                    <div class="field"><label for="yt-guests">{{ __('site.quote.guests') }}</label>
                        <div class="stepper" data-stepper><button type="button" data-step="-1" aria-label="-">−</button><input id="yt-guests" type="number" name="guests" min="1" max="{{ $yacht['guests'] ?? 200 }}" value="{{ min(6, (int) ($yacht['guests'] ?? 6)) }}"><button type="button" data-step="1" aria-label="+">+</button></div>
                    </div>
                    <div class="field" data-extras-wrap hidden><span class="label">{{ __('site.quote.extras') }}</span><div class="extras" data-extras></div></div>
                    <div class="est" data-est aria-live="polite"><p class="muted small">{{ __('site.quote.pick_dates') }}</p></div>
                    <button type="button" class="btn btn-red btn-block btn-l" data-next>{{ __('site.booking.book_now') }} <svg class="i flip"><use href="#i-arrow"/></svg></button>
                    @if ($wa)<a class="btn btn-ghost btn-block" href="{{ $wa }}" data-wa-dates target="_blank" rel="noopener"><svg class="i" style="color:#25d366"><use href="#i-whatsapp"/></svg>{{ __('site.common.ask_whatsapp') }}</a>@endif
                    <div class="bk-trust"><span><svg class="i"><use href="#i-anchor"/></svg>{{ __('site.common.captain_included') }}</span></div>
                </div>
                @include('site.partials.request-steps', ['p' => 'yt'])
            </div>
        </form>
    </aside>
</div>

@if (count($others))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.nav.yachts') }}</span><h2>{{ __('site.yachts.others') }}</h2></div></div>
        <div class="grid-cards">@foreach ($others as $y)@include('site.partials.yacht-card', ['yacht' => $y])@endforeach</div>
    </div>
</section>
@endif

<div class="mbar">
    <div class="price"><small>{{ __('site.quote.mobile_from') }}</small><b>@if ($from){{ Site::money($from) }} <span>{{ ! empty($p['hourly']) ? __('site.common.per_hour') : '' }}</span>@else{{ __('site.common.price_on_request') }}@endif</b></div>
    <div class="flex gap-1">
        @if ($wa)<a class="icon-btn wa" href="{{ $wa }}" target="_blank" rel="noopener" aria-label="{{ __('site.common.ask_whatsapp') }}"><svg class="i"><use href="#i-whatsapp"/></svg></a>@endif
        <a class="btn btn-red" href="#book">{{ __('site.common.book_now') }}</a>
    </div>
</div>
@endsection
