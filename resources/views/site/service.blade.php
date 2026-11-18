@extends('site.layout')
@php
    use App\Support\Site\Site;
    $wa = Site::whatsapp(__('site.wa.hello', ['item' => $t['title']]));
    $actions = '<div class="hero-cta"><button type="button" class="btn btn-red btn-l" data-open-quote data-service="'.e($svc['lead']).'" data-item="'.e($t['title']).'">'.e(__('site.nav.quote')).'</button>'
        .($wa ? '<a class="btn btn-glass btn-l" href="'.e($wa).'" target="_blank" rel="noopener"><svg class="i" style="color:#25d366"><use href="#i-whatsapp"/></svg>'.e(__('site.nav.whatsapp')).'</a>' : '').'</div>';
@endphp
@section('content')
@include('site.partials.phead', ['crumbs' => [[__('site.nav.services'), Site::route('services')], [$t['title'], null]], 'eyebrow' => __('site.services.eyebrow'), 'title' => $t['seo'] ?? $t['title'], 'lead' => $t['lead'], 'bg' => Site::img($svc['image']), 'actions' => $actions])

<section class="sec">
    <div class="wrap split" style="align-items:start">
        <div class="rv grid" style="gap:18px">
            <span class="eyebrow">{{ $t['title'] }}</span>
            <p class="muted" style="font-size:17px">{{ $t['p1'] }}</p>
            <p class="muted" style="font-size:17px">{{ $t['p2'] }}</p>
            <ul class="ticks">@foreach ($t['points'] as $pt)<li><svg class="i"><use href="#i-check-c"/></svg>{{ $pt }}</li>@endforeach</ul>
            <div class="flex wrapf gap-1">
                @if ($slug === 'airport-transfers' && Site::has('limousine'))<a class="btn btn-red" href="{{ Site::route('limousine') }}">{{ __('site.home.limo_cta') }}</a>@endif
                @if ($slug === 'yacht-charter')<a class="btn btn-red" href="{{ Site::route('yachts') }}">{{ __('site.home.yacht_cta') }}</a>@endif
                @if (count($cars))<a class="btn btn-ghost" href="{{ $svc['cars'] === 'with_driver' ? Site::route('cars', ['mode' => 'with_driver']) : ($svc['cars'] === 'self_drive' ? Site::route('cars', ['mode' => 'self_drive']) : Site::route('cars')) }}">{{ __('site.home.cta_fleet') }}</a>@endif
            </div>
        </div>
        <form class="panel rv" data-enquiry novalidate>
            <h2 style="font-size:22px">{{ __('site.services.form_title') }}</h2>
            @include('site.partials.enquiry-fields', ['p' => 'sv', 'service' => $svc['lead'], 'withItem' => true, 'item' => $t['title']])
        </form>
    </div>
</section>

@if (count($cars))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.home.fleet_eyebrow') }}</span><h2>{{ __('site.services.cars_title') }}</h2></div><a class="link-more" href="{{ Site::route('cars') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        <div class="grid-cards">@foreach ($cars as $car)@include('site.partials.car-card', ['car' => $car])@endforeach</div>
    </div>
</section>
@endif
@if (count($yachts))
<section class="sec sec-alt">
    <div class="wrap"><div class="grid-cards">@foreach ($yachts as $y)@include('site.partials.yacht-card', ['yacht' => $y])@endforeach</div></div>
</section>
@endif

<section class="sec">
    <div class="wrap">
        <div class="sec-head rv" style="justify-content:center;text-align:center"><div><span class="eyebrow">{{ __('site.home.steps_eyebrow') }}</span><h2>{{ __('site.home.steps_title') }}</h2></div></div>
        <ol class="steps">@foreach (__('site.home.steps') as $i => $st)<li class="step rv"><div class="step-n">{{ $i + 1 }}</div><h3>{{ $st['title'] }}</h3><p>{{ $st['text'] }}</p></li>@endforeach</ol>
    </div>
</section>

@if (count($others))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.services.eyebrow') }}</span><h2>{{ __('site.services.others') }}</h2></div></div>
        @include('site.partials.service-cards', ['services' => array_slice($others, 0, 4, true)])
    </div>
</section>
@endif
@include('site.partials.cta')
@endsection
