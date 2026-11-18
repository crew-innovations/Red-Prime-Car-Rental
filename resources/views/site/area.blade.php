@extends('site.layout')
@php
    use App\Support\Site\Site;
    $item = __('site.areas.quote_item', ['area' => $name]);
    $wa = Site::whatsapp(__('site.wa.hello', ['item' => $item]));
    $actions = '<div class="hero-cta"><button type="button" class="btn btn-red btn-l" data-open-quote data-service="car" data-item="'.e($item).'">'.e(__('site.nav.quote')).'</button>'
        .($wa ? '<a class="btn btn-glass btn-l" href="'.e($wa).'" target="_blank" rel="noopener"><svg class="i" style="color:#25d366"><use href="#i-whatsapp"/></svg>'.e(__('site.nav.whatsapp')).'</a>' : '').'</div>';
@endphp
@section('content')
@include('site.partials.phead', ['crumbs' => [[__('site.nav.areas'), Site::route('areas')], [$name, null]], 'eyebrow' => __('site.areas.deliver_to', ['area' => $name]), 'title' => __('site.areas.page_title', ['area' => $name]), 'lead' => __('site.areas.page_lead', ['area' => $name]), 'bg' => Site::img($area['image']), 'actions' => $actions])

<section class="sec">
    <div class="wrap split" style="align-items:start">
        <div class="rv grid" style="gap:18px">
            <span class="eyebrow">{{ $name }}</span>
            <p class="muted" style="font-size:18px">{{ __('site.areas.items.'.$slug.'.text') }}</p>
            <h2 style="font-size:26px;margin-top:10px">{{ __('site.areas.how_title') }}</h2>
            <ol class="vsteps">
                @foreach (__('site.areas.how') as $i => $st)
                    <li class="rv"><span class="vstep-n">{{ $i + 1 }}</span><div><b>{{ $st['title'] }}</b><p class="muted">{{ str_replace(':area', $name, $st['text']) }}</p></div></li>
                @endforeach
            </ol>
        </div>
        <form class="panel rv" data-enquiry novalidate>
            <h2 style="font-size:22px">{{ __('site.areas.deliver_to', ['area' => $name]) }}</h2>
            @include('site.partials.enquiry-fields', ['p' => 'ar', 'service' => 'car', 'withItem' => true, 'item' => $item])
        </form>
    </div>
</section>

@if (count($cars))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.home.fleet_eyebrow') }}</span><h2>{{ __('site.areas.cars_title') }}</h2></div><a class="link-more" href="{{ Site::route('cars') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        <div class="grid-cards">@foreach ($cars as $car)@include('site.partials.car-card', ['car' => $car])@endforeach</div>
    </div>
</section>
@endif

<section class="sec">
    <div class="wrap">
        <div class="sec-head rv"><div><h2 style="font-size:28px">{{ __('site.areas.nearby') }}</h2></div><a class="link-more" href="{{ Site::route('areas') }}">{{ __('site.areas.all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        <div class="chips">@foreach ($area['near'] as $n)<a class="chip" href="{{ Site::route('area', ['slug' => $n]) }}"><svg class="i i-s gold"><use href="#i-pin"/></svg>{{ __('site.areas.items.'.$n.'.name') }}</a>@endforeach</div>
    </div>
</section>
@include('site.partials.cta')
@endsection
