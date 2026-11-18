@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('content')
<section class="phead phead-img">
    <div class="phead-bg" aria-hidden="true" data-parallax><img src="{{ Site::yachtImage() }}" alt="" fetchpriority="high"></div>
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ __('site.nav.trips') }}</span></nav>
        <span class="eyebrow mt-2">{{ __('site.trips.eyebrow') }}</span>
        <h1 data-split>{{ __('site.trips.title') }}</h1>
        <p>{{ __('site.trips.lead') }}</p>
    </div>
</section>
<section class="sec" style="padding-top:36px">
    <div class="wrap">
        @if (count($trips))
            <div class="grid-cards">@foreach ($trips as $item)@include('site.partials.trip-card', ['item' => $item])@endforeach</div>
        @else
            <div class="empty"><div class="ico"><svg class="i i-l"><use href="#i-anchor"/></svg></div><p>{{ __('site.trips.empty') }}</p></div>
        @endif
    </div>
</section>
@include('site.partials.cta')
@endsection
