@extends('site.layout')
@php
    use App\Support\Site\Site;
    $cars = Site::has('cars') ? \App\Support\Site\Catalog::cars() : collect();
    $img = $cars->first(fn ($c) => ! empty($c['images']['cover']['large']))['images']['cover'] ?? null;
@endphp
@section('content')
<section class="phead">
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ __('site.nav.about') }}</span></nav>
        <span class="eyebrow mt-2">{{ __('site.about.eyebrow') }}</span>
        <h1 data-split>{{ __('site.about.title') }}</h1>
        <p>{{ __('site.about.lead') }}</p>
    </div>
</section>
<section class="sec">
    <div class="wrap split">
        <div class="spot-media rv">@include('site.partials.media', ['img' => $img, 'alt' => Site::company(), 'kind' => 'car', 'size' => 'large'])</div>
        <div class="rv grid" style="gap:16px">
            <span class="eyebrow">{{ Site::company() }}</span>
            <p class="muted" style="font-size:17px">{{ __('site.about.p1') }}</p>
            <p class="muted" style="font-size:17px">{{ __('site.about.p2') }}</p>
            <div class="flex wrapf gap-1 mt-2">
                @if (Site::has('cars'))<a class="btn btn-red" href="{{ Site::route('cars') }}">{{ __('site.home.cta_fleet') }}</a>@endif
                <a class="btn btn-ghost" href="{{ Site::route('contact') }}">{{ __('site.nav.contact') }}</a>
            </div>
        </div>
    </div>
</section>
<section class="sec sec-alt">
    <div class="wrap features">
        @foreach (__('site.about.values') as $i => $v)
            <div class="feature rv"><div class="ico"><svg class="i i-l"><use href="#i-{{ ['car', 'tag', 'users'][$i] ?? 'star' }}"/></svg></div><h3>{{ $v['title'] }}</h3><p>{{ $v['text'] }}</p></div>
        @endforeach
    </div>
</section>
@include('site.partials.cta')
@endsection
