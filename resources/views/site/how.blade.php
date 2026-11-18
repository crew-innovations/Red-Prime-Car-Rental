@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('content')
@include('site.partials.phead', ['crumbs' => [[__('site.nav.how'), null]], 'eyebrow' => __('site.how.eyebrow'), 'title' => __('site.how.title'), 'lead' => __('site.how.lead'), 'bg' => Site::img('photo-g63-dubai.jpg')])
<section class="sec">
    <div class="wrap" style="max-width:980px">
        <ol class="timeline how-steps">
            @foreach (__('site.how.steps') as $i => $st)
                <li class="rv"><small>{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</small><b style="font-size:20px">{{ $st['title'] }}</b><p>{{ $st['text'] }}</p></li>
            @endforeach
        </ol>
    </div>
</section>
<section class="sec sec-alt">
    <div class="wrap features">
        <div class="feature rv"><div class="ico"><svg class="i i-l"><use href="#i-shield"/></svg></div><h3>{{ __('site.how.need_title') }}</h3>
            <ul class="ticks" style="margin:0">@foreach (__('site.how.need') as $n)<li><svg class="i"><use href="#i-check-c"/></svg><span class="muted">{{ $n }}</span></li>@endforeach</ul></div>
        <div class="feature rv"><div class="ico"><svg class="i i-l"><use href="#i-card"/></svg></div><h3>{{ __('site.how.pay_title') }}</h3><p>{{ __('site.how.pay') }}</p></div>
        <div class="feature rv"><div class="ico"><svg class="i i-l"><use href="#i-pin"/></svg></div><h3>{{ __('site.services.items.car-delivery.title') }}</h3><p>{{ __('site.services.items.car-delivery.p1') }}</p></div>
    </div>
</section>
<section class="sec">
    <div class="wrap" style="max-width:920px">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.home.faq_eyebrow') }}</span><h2>{{ __('site.home.faq_title') }}</h2></div><a class="link-more" href="{{ Site::route('faq') }}">{{ __('site.home.faq_more') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        @include('site.partials.faq', ['faqs' => array_slice(__('site.faq.items'), 0, 5)])
    </div>
</section>
@include('site.partials.cta')
@endsection
