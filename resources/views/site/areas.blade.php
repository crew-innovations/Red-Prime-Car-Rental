@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('content')
@include('site.partials.phead', ['crumbs' => [[__('site.nav.areas'), null]], 'eyebrow' => __('site.areas.eyebrow'), 'title' => __('site.areas.title'), 'lead' => __('site.areas.lead'), 'bg' => Site::img('fleet-escalade.webp')])
<section class="sec" style="padding-top:48px">
    <div class="wrap">
        <div class="area-grid">
            @foreach ($areas as $slug => $a)
                <a class="area rv tilt" href="{{ Site::route('area', ['slug' => $slug]) }}">
                    <img src="{{ Site::img($a['image']) }}" alt="" loading="lazy">
                    <span class="area-pin"><svg class="i"><use href="#i-pin"/></svg></span>
                    <span class="area-txt"><b>{{ __('site.areas.items.'.$slug.'.name') }}</b><small>{{ __('site.areas.deliver_to', ['area' => __('site.areas.items.'.$slug.'.name')]) }}</small></span>
                    <span class="arrow"><svg class="i flip"><use href="#i-arrow"/></svg></span>
                </a>
            @endforeach
        </div>
        <p class="muted small mt-4" style="text-align:center">{{ __('site.areas.not_listed') }}</p>
    </div>
</section>
@include('site.partials.cta')
@endsection
