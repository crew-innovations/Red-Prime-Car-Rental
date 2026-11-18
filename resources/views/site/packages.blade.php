@extends('site.layout')
@php use App\Support\Site\Site; $cats = collect($packages)->pluck('category.name')->filter()->unique()->values(); @endphp
@section('content')
<section class="phead phead-img">
    <div class="phead-bg" aria-hidden="true" data-parallax><img src="{{ Site::yachtImage() }}" alt="" fetchpriority="high"></div>
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ __('site.nav.packages') }}</span></nav>
        <span class="eyebrow mt-2">{{ __('site.packages.eyebrow') }}</span>
        <h1 data-split>{{ __('site.packages.title') }}</h1>
        <p>{{ __('site.packages.lead') }}</p>
    </div>
</section>
<section class="sec" style="padding-top:36px">
    <div class="wrap">
        @if (count($cats) > 1)
            <div class="filter-row" role="group" data-chip-filter data-chip-target="[data-grid]">
                <button type="button" class="chip on" aria-pressed="true" data-filter="">{{ __('site.home.all') }}</button>
                @foreach ($cats as $c)<button type="button" class="chip" aria-pressed="false" data-filter="{{ $c }}">{{ $c }}</button>@endforeach
            </div>
        @endif
        @if (count($packages))
            <div class="grid-cards" data-grid>@foreach ($packages as $item)<div data-body="{{ $item['category']['name'] ?? '' }}" style="display:contents">@include('site.partials.trip-card', ['item' => $item])</div>@endforeach</div>
        @else
            <div class="empty"><div class="ico"><svg class="i i-l"><use href="#i-sparkle"/></svg></div><p>{{ __('site.packages.empty') }}</p></div>
        @endif
    </div>
</section>
@include('site.partials.cta')
@endsection
