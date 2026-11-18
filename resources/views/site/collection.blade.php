@extends('site.layout')
@php
    use App\Support\Site\Site;
    $isBrand = $kind === 'brand';
    $title = __($isBrand ? 'site.collection.brand_title' : 'site.collection.type_title', ['name' => $name]);
    $crumbs = $isBrand ? [[__('site.nav.cars'), Site::route('cars')], [__('site.nav.brands'), Site::route('brands')], [$name, null]] : [[__('site.nav.cars'), Site::route('cars')], [$name, null]];
    $logoHtml = ! empty($logo) ? '<span class="phead-logo"><img src="'.e($logo).'" alt="'.e($name).'"></span>' : '';
    $actions = $logoHtml.'<div class="hero-cta">'.(count($cars) ? '<a class="btn btn-red btn-l" href="#cars">'.e(trans_choice('site.cars.count', count($cars), ['count' => count($cars)])).'</a>' : '').'<button type="button" class="btn btn-glass btn-l" data-open-quote data-service="car" data-item="'.e($name).'">'.e(__('site.nav.quote')).'</button></div>';
@endphp
@section('content')
@include('site.partials.phead', ['crumbs' => $crumbs, 'eyebrow' => $isBrand ? __('site.brands.eyebrow') : __('site.nav.types'), 'title' => $title, 'lead' => __($isBrand ? 'site.collection.brand_lead' : 'site.collection.type_lead', ['name' => $name]), 'bg' => $image, 'actions' => $actions])

<section class="sec" id="cars" style="padding-top:40px">
    <div class="wrap">
        @if (count($cars))
            <div class="grid-cards">@foreach ($cars as $car)@include('site.partials.car-card', ['car' => $car])@endforeach</div>
        @else
            @if ($isBrand)
                <div class="brand-empty rv">
                    <div>
                        <h2>{{ __('site.collection.brand_empty_title', ['name' => $name]) }}</h2>
                        <p class="muted">{{ __('site.collection.brand_empty', ['name' => $name]) }}</p>
                        <div class="hero-cta">
                            <button type="button" class="btn btn-red btn-l" data-open-quote data-service="car" data-item="{{ $name }}">{{ __('site.nav.quote') }}</button>
                            @if ($wa = Site::whatsapp(__('site.collection.brand_wa', ['name' => $name])))<a class="btn btn-wa btn-l" href="{{ $wa }}" target="_blank" rel="noopener"><svg class="i"><use href="#i-whatsapp"/></svg>{{ __('site.nav.whatsapp') }}</a>@endif
                            <a class="btn btn-ghost btn-l" href="{{ Site::route('cars') }}">{{ __('site.nav.all_cars') }}</a>
                        </div>
                    </div>
                    <img src="{{ $image }}" alt="" loading="lazy">
                </div>
            @else
                <div class="empty"><div class="ico"><svg class="i i-l"><use href="#i-car"/></svg></div><p>{{ __('site.collection.empty') }}</p></div>
            @endif
        @endif
    </div>
</section>

@if (count($links))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv"><div><h2 style="font-size:28px">{{ $isBrand ? __('site.collection.other_brands') : __('site.collection.other_types') }}</h2></div></div>
        <div class="chips">@foreach ($links as $l)<a class="chip" href="{{ $l['url'] }}">@if (! empty($l['logo']))<img class="chip-logo" src="{{ $l['logo'] }}" alt="" loading="lazy">@endif{{ $l['name'] }} @if ($l['count'])<small class="muted">{{ $l['count'] }}</small>@endif</a>@endforeach</div>
    </div>
</section>
@endif

<section class="sec">
    <div class="wrap" style="max-width:920px">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.home.faq_eyebrow') }}</span><h2>{{ __('site.home.faq_title') }}</h2></div></div>
        @include('site.partials.faq', ['faqs' => array_slice(__('site.faq.items'), 0, 4)])
    </div>
</section>
@include('site.partials.cta')
@endsection
