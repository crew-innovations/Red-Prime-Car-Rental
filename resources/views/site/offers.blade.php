@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('content')
@include('site.partials.phead', ['crumbs' => [[__('site.nav.offers'), null]], 'eyebrow' => __('site.offers.eyebrow'), 'title' => __('site.offers.title'), 'lead' => __('site.offers.lead'), 'bg' => Site::img('fleet-brabus.webp')])

<section class="sec" style="padding-top:48px">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.offers.eyebrow') }}</span><h2>{{ __('site.offers.active') }}</h2></div></div>
        @if (count($offers))
            <div class="offer-grid">@foreach ($offers as $o)@include('site.partials.offer-card', ['o' => $o])@endforeach</div>
        @else
            <div class="empty rv"><div class="ico"><svg class="i i-l"><use href="#i-tag"/></svg></div><p>{{ __('site.offers.none') }}</p>
                @if ($wa = Site::whatsapp(__('site.wa.general')))<a class="btn btn-wa" href="{{ $wa }}" target="_blank" rel="noopener"><svg class="i"><use href="#i-whatsapp"/></svg>{{ __('site.nav.whatsapp') }}</a>@endif
            </div>
        @endif
    </div>
</section>

@if (count($deals))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.nav.cars') }}</span><h2>{{ __('site.offers.deals_title') }}</h2><p>{{ __('site.offers.deals_text') }}</p></div></div>
        <div class="deal-grid">
            @foreach ($deals as $car)
                @php $d = $car['_deal']; @endphp
                <a class="deal rv tilt" href="{{ Site::route('car', ['slug' => $car['slug']]) }}">
                    <span class="deal-img">@include('site.partials.media', ['img' => $car['images']['cover'] ?? null, 'alt' => $car['name'], 'kind' => 'car'])<span class="offer-badge">{{ __('site.offers.save', ['n' => $d['save']]) }}</span></span>
                    <span class="deal-body">
                        <span class="card-tag">{{ $car['brand'] ?? '' }}</span>
                        <b>{{ $car['name'] }}</b>
                        <span class="deal-rows">
                            <span><small>{{ __('site.offers.per_day_daily') }}</small><s>{{ Site::money($d['daily']) }}</s></span>
                            @if ($d['week_day'])<span><small>{{ __('site.offers.per_day_week') }}</small><b>{{ Site::money($d['week_day']) }}</b></span>@endif
                            @if ($d['month_day'])<span><small>{{ __('site.offers.per_day_month') }}</small><b class="gold">{{ Site::money($d['month_day']) }}</b></span>@endif
                        </span>
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif
@include('site.partials.cta')
@endsection
