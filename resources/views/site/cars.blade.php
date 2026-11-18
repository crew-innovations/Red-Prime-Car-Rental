@extends('site.layout')
@php
    use App\Support\Site\Site;
    $today = now()->toDateString();
    $fromD = ! empty($f['start']) ? $f['start']->toDateString() : '';
    $fromT = ! empty($f['start']) ? $f['start']->format('H:i') : '10:00';
    $toD = ! empty($f['end']) ? $f['end']->toDateString() : '';
    $toT = ! empty($f['end']) ? $f['end']->format('H:i') : '10:00';
    $times = collect(range(0, 47))->map(fn ($i) => sprintf('%02d:%02d', intdiv($i, 2), $i % 2 ? 30 : 0));
    $brandsOn = (array) ($f['brand'] ?? []);
    $bodiesOn = (array) ($f['body'] ?? []);
    $maxCap = (int) (ceil(max(1000, $options['max_price']) / 500) * 500);
    $maxOn = (int) ($f['max'] ?? $maxCap);
    $query = request()->query();
    $without = function (string $key, $value = null) use ($query) {
        $q = $query;
        if ($value === null) { unset($q[$key]); } else { $q[$key] = array_values(array_diff((array) ($q[$key] ?? []), [$value])); }
        if ($key === 'from' || $key === 'to') { unset($q['from'], $q['to'], $q['available']); }
        return Site::route('cars', array_filter($q, fn ($v) => $v !== [] && $v !== null && $v !== ''));
    };
    $active = collect();
    foreach ($brandsOn as $b) { $active->push([$b, $without('brand', $b)]); }
    foreach ($bodiesOn as $b) { $active->push([$b, $without('body', $b)]); }
    if (! empty($f['mode'])) { $active->push([$f['mode'] === 'with_driver' ? __('site.common.with_chauffeur') : __('site.common.self_drive'), $without('mode')]); }
    if (! empty($f['seats'])) { $active->push([__('site.cars.seats_min', ['n' => $f['seats']]), $without('seats')]); }
    if (! empty($f['max'])) { $active->push([__('site.cars.up_to', ['price' => Site::money((float) $f['max'])]), $without('max')]); }
    if (! empty($f['q'])) { $active->push(['"'.$f['q'].'"', $without('q')]); }
    if (! empty($f['start'])) { $active->push([$f['start']->translatedFormat('j M').' → '.$f['end']->translatedFormat('j M'), $without('from')]); }
@endphp

@section('content')
<section class="phead">
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ __('site.nav.cars') }}</span></nav>
        <span class="eyebrow mt-2">{{ __('site.cars.eyebrow') }}</span>
        <h1 data-split>{{ ($f['mode'] ?? null) === 'with_driver' ? __('site.common.with_chauffeur').' · ' : '' }}{{ __('site.cars.title') }}</h1>
        <p>{{ __('site.cars.lead') }}</p>
    </div>
</section>

<section class="sec" style="padding-top:36px">
    <div class="wrap listing">
        <form class="filters" id="filters" method="get" action="{{ Site::route('cars') }}" data-filters data-dt-form aria-label="{{ __('site.common.filters') }}">
            <div class="filters-head"><b>{{ __('site.common.filters') }}</b><button type="button" class="tool" data-filters-close aria-label="{{ __('site.common.close') }}"><svg class="i"><use href="#i-x"/></svg></button></div>
            <div class="fgroup">
                <h3><label for="f-q">{{ __('site.cars.search') }}</label></h3>
                <input class="input" id="f-q" type="search" name="q" value="{{ $f['q'] ?? '' }}" placeholder="{{ __('site.cars.search_placeholder') }}">
            </div>
            <div class="fgroup">
                <h3>{{ __('site.cars.dates') }}</h3>
                <div class="field"><span class="label">{{ __('site.cars.pickup') }}</span><div class="dt">
                    <input class="input" type="date" name="from_d" value="{{ $fromD }}" min="{{ $today }}" aria-label="{{ __('site.cars.pickup') }}">
                    <select class="select" name="from_t" aria-label="{{ __('site.home.time') }}">@foreach ($times as $t)<option @selected($t === $fromT)>{{ $t }}</option>@endforeach</select>
                </div></div>
                <div class="field"><span class="label">{{ __('site.cars.return') }}</span><div class="dt">
                    <input class="input" type="date" name="to_d" value="{{ $toD }}" min="{{ $today }}" aria-label="{{ __('site.cars.return') }}">
                    <select class="select" name="to_t" aria-label="{{ __('site.home.time') }}">@foreach ($times as $t)<option @selected($t === $toT)>{{ $t }}</option>@endforeach</select>
                </div></div>
                <input type="hidden" name="from" value="{{ ! empty($f['start']) ? $f['start']->format('Y-m-d H:i') : '' }}"><input type="hidden" name="to" value="{{ ! empty($f['end']) ? $f['end']->format('Y-m-d H:i') : '' }}">
                <label class="check"><input type="checkbox" name="available" value="1" @checked(! empty($f['available']))>{{ __('site.cars.only_available') }}</label>
            </div>
            <div class="fgroup">
                <h3>{{ __('site.cars.mode') }}</h3>
                <div class="seg">
                    <label><input type="radio" name="mode" value="" @checked(empty($f['mode']))>{{ __('site.cars.any') }}</label>
                    <label><input type="radio" name="mode" value="self_drive" @checked(($f['mode'] ?? '') === 'self_drive')>{{ __('site.common.self_drive') }}</label>
                    <label><input type="radio" name="mode" value="with_driver" @checked(($f['mode'] ?? '') === 'with_driver')>{{ __('site.common.with_chauffeur') }}</label>
                </div>
            </div>
            @if (count($options['bodies']) > 1)
            <div class="fgroup">
                <h3>{{ __('site.cars.body') }}</h3>
                <div class="chips">@foreach ($options['bodies'] as $b)<label class="chip"><input type="checkbox" name="body[]" value="{{ $b }}" @checked(in_array($b, $bodiesOn, true))>{{ $b }}</label>@endforeach</div>
            </div>
            @endif
            @if (count($options['brands']) > 1)
            <div class="fgroup">
                <h3>{{ __('site.cars.brand') }}</h3>
                @foreach ($options['brands'] as $b)<label class="check"><input type="checkbox" name="brand[]" value="{{ $b }}" @checked(in_array($b, $brandsOn, true))>{{ $b }}</label>@endforeach
            </div>
            @endif
            @if (count($options['seats']) > 1)
            <div class="fgroup">
                <h3><label for="f-seats">{{ __('site.cars.seats') }}</label></h3>
                <select class="select" id="f-seats" name="seats">
                    <option value="">{{ __('site.cars.seats_any') }}</option>
                    @foreach ($options['seats'] as $n)<option value="{{ $n }}" @selected((int) ($f['seats'] ?? 0) === (int) $n)>{{ __('site.cars.seats_min', ['n' => $n]) }}</option>@endforeach
                </select>
            </div>
            @endif
            @if ($options['max_price'] > 0)
            <div class="fgroup">
                <h3><label for="f-max">{{ __('site.cars.max_price') }}</label></h3>
                <input id="f-max" type="range" name="max" min="{{ (int) (floor($options['min_price'] / 500) * 500) }}" max="{{ $maxCap }}" step="100" value="{{ $maxOn }}" data-range-out="#f-max-out" data-range-max="{{ $maxCap }}">
                <span class="muted small" id="f-max-out" data-fmt="{{ __('site.cars.up_to', ['price' => ':price']) }}">{{ __('site.cars.up_to', ['price' => Site::money($maxOn)]) }}</span>
            </div>
            @endif
            <input type="hidden" name="sort" value="{{ $f['sort'] ?? '' }}">
            <div class="fgroup filters-apply">
                <button class="btn btn-red btn-block" type="submit">{{ __('site.cars.show') }}</button>
                @if ($active->isNotEmpty())<a class="btn btn-ghost btn-block btn-s" href="{{ Site::route('cars') }}">{{ __('site.cars.clear') }}</a>@endif
            </div>
        </form>

        <div>
            <div class="toolbar">
                <span class="count">{{ trans_choice('site.cars.count', count($cars), ['count' => count($cars)]) }}</span>
                <div class="toolbar-r">
                    <button type="button" class="btn btn-ghost btn-s filters-toggle" data-filters-open aria-controls="filters"><svg class="i i-s"><use href="#i-filter"/></svg>{{ __('site.common.filters') }}@if ($active->count()) ({{ $active->count() }})@endif</button>
                    <label class="sr-only" for="sort">{{ __('site.common.sort') }}</label>
                    <select class="select" id="sort" data-sort-select>
                        @foreach (__('site.cars.sort') as $k => $label)<option value="{{ $k }}" @selected(($f['sort'] ?? 'featured') === $k)>{{ $label }}</option>@endforeach
                    </select>
                    <div class="view-btns hide-m" role="group" data-view-btns>
                        <button type="button" aria-pressed="true" data-view="grid" aria-label="{{ __('site.common.grid') }}"><svg class="i i-s"><use href="#i-grid"/></svg></button>
                        <button type="button" aria-pressed="false" data-view="list" aria-label="{{ __('site.common.list') }}"><svg class="i i-s"><use href="#i-list"/></svg></button>
                    </div>
                </div>
            </div>
            @if ($active->isNotEmpty())
                <div class="active-filters">@foreach ($active as [$label, $url])<a href="{{ $url }}">{{ $label }} <svg class="i i-s"><use href="#i-x"/></svg></a>@endforeach</div>
            @endif

            @if (count($cars))
                <div class="grid-cards" data-view-target>@foreach ($cars as $car)@include('site.partials.car-card', ['car' => $car])@endforeach</div>
            @else
                <div class="empty">
                    <div class="ico"><svg class="i i-l"><use href="#i-car"/></svg></div>
                    <h3>{{ __('site.cars.empty_title') }}</h3>
                    <p>{{ __('site.cars.empty_text') }}</p>
                    <div class="flex wrapf gap-1" style="justify-content:center">
                        <a class="btn btn-ghost" href="{{ Site::route('cars') }}">{{ __('site.cars.clear') }}</a>
                        @if ($wa = Site::whatsapp(__('site.wa.general')))<a class="btn btn-wa" href="{{ $wa }}" target="_blank" rel="noopener"><svg class="i"><use href="#i-whatsapp"/></svg>{{ __('site.nav.whatsapp') }}</a>@endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>

@include('site.partials.cta')
@endsection
