@extends('site.layout')
@php use App\Support\Site\Site; @endphp

@section('content')
<section class="phead phead-img">
    <div class="phead-bg" aria-hidden="true" data-parallax><img src="{{ Site::yachtImage() }}" alt="" fetchpriority="high"></div>
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ __('site.nav.yachts') }}</span></nav>
        <span class="eyebrow mt-2">{{ __('site.yachts.eyebrow') }}</span>
        <h1 data-split>{{ __('site.yachts.title') }}</h1>
        <p>{{ __('site.yachts.lead') }}</p>
        <form class="flex wrapf gap-1 mt-3" method="get" action="{{ Site::route('yachts') }}" style="align-items:end">
            <div class="field" style="width:170px"><label for="y-guests">{{ __('site.yachts.guests') }}</label>
                <div class="stepper" data-stepper><button type="button" data-step="-1" aria-label="-">−</button><input id="y-guests" type="number" name="guests" min="1" max="{{ max(1, $maxGuests) }}" value="{{ $f['guests'] ?? '' }}" placeholder="—"><button type="button" data-step="1" aria-label="+">+</button></div>
            </div>
            @if (count($marinas) > 1)
                <div class="field" style="min-width:200px"><label for="y-marina">{{ __('site.yachts.marina') }}</label>
                    <select class="select" id="y-marina" name="marina"><option value="">{{ __('site.yachts.any') }}</option>@foreach ($marinas as $m)<option @selected(($f['marina'] ?? '') === $m)>{{ $m }}</option>@endforeach</select>
                </div>
            @endif
            <div class="field" style="min-width:200px"><label for="y-sort">{{ __('site.common.sort') }}</label>
                <select class="select" id="y-sort" name="sort">@foreach (__('site.yachts.sort') as $k => $label)<option value="{{ $k }}" @selected(($f['sort'] ?? 'featured') === $k)>{{ $label }}</option>@endforeach</select>
            </div>
            <button class="btn btn-red" type="submit" style="min-height:50px"><svg class="i"><use href="#i-search"/></svg>{{ __('site.common.apply') }}</button>
            @if (array_filter($f))<a class="btn btn-ghost" style="min-height:50px" href="{{ Site::route('yachts') }}">{{ __('site.common.reset') }}</a>@endif
        </form>
    </div>
</section>

<section class="sec" style="padding-top:36px">
    <div class="wrap">
        <div class="toolbar"><span class="count">{{ trans_choice('site.yachts.count', count($yachts), ['count' => count($yachts)]) }}</span></div>
        @if (count($yachts))
            <div class="grid-cards">@foreach ($yachts as $yacht)@include('site.partials.yacht-card', ['yacht' => $yacht])@endforeach</div>
        @else
            <div class="empty"><div class="ico"><svg class="i i-l"><use href="#i-yacht"/></svg></div><h3>{{ __('site.yachts.empty_title') }}</h3><p>{{ __('site.yachts.empty_text') }}</p><a class="btn btn-ghost" href="{{ Site::route('yachts') }}">{{ __('site.common.reset') }}</a></div>
        @endif
    </div>
</section>

@if (count($trips))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.nav.trips') }}</span><h2>{{ __('site.home.trips_title') }}</h2><p>{{ __('site.trips.lead') }}</p></div><a class="link-more" href="{{ Site::route('trips') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        <div class="grid-cards">@foreach ($trips as $item)@include('site.partials.trip-card', ['item' => $item])@endforeach</div>
    </div>
</section>
@endif

@include('site.partials.cta')
@endsection
