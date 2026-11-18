@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('content')
<section class="phead">
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ __('site.nav.faq') }}</span></nav>
        <span class="eyebrow mt-2">{{ __('site.faq.eyebrow') }}</span>
        <h1 data-split>{{ __('site.faq.title') }}</h1>
        <p>{{ __('site.faq.lead') }}</p>
    </div>
</section>
<section class="sec" style="padding-top:36px">
    <div class="wrap" style="max-width:920px">@include('site.partials.faq', ['faqs' => $faqs])</div>
</section>
@include('site.partials.cta')
@endsection
