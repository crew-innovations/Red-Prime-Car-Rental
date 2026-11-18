@extends('site.layout')
@php
    use App\Support\Site\Site;
    $title = $post->local('title');
    $share = Site::absolute(Site::route('post', ['slug' => $post->slug]));
@endphp
@section('content')
<section class="phead phead-img post-head">
    <div class="phead-bg" aria-hidden="true" data-parallax><img src="{{ $image }}" alt="" fetchpriority="high"></div>
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><a href="{{ Site::route('blog') }}">{{ __('site.nav.blog') }}</a></nav>
        <span class="eyebrow mt-2">{{ $post->published_at?->locale(Site::locale())->translatedFormat('j F Y') }} · {{ __('site.blog.minutes', ['n' => $post->readMinutes()]) }}</span>
        <h1 data-split>{{ $title }}</h1>
        @if ($post->local('excerpt'))<p>{{ $post->local('excerpt') }}</p>@endif
    </div>
</section>
<section class="sec" style="padding-top:48px">
    <div class="wrap post-layout">
        <article class="article rv">{!! Site::safeHtml($post->local('body')) !!}</article>
        <aside class="post-aside">
            <div class="panel rv">
                <h3 style="font-size:20px">{{ __('site.blog.cta_title') }}</h3>
                <p class="muted">{{ __('site.blog.cta_text') }}</p>
                <button type="button" class="btn btn-red btn-block" data-open-quote data-item="{{ $title }}">{{ __('site.nav.quote') }}</button>
                @if ($wa = Site::whatsapp(__('site.wa.general')))<a class="btn btn-wa btn-block" href="{{ $wa }}" target="_blank" rel="noopener"><svg class="i"><use href="#i-whatsapp"/></svg>{{ __('site.nav.whatsapp') }}</a>@endif
                <div class="share"><span class="small muted">{{ __('site.blog.share') }}</span>
                    <a class="icon-btn wa" href="https://wa.me/?text={{ urlencode($title.' '.$share) }}" target="_blank" rel="noopener" aria-label="WhatsApp"><svg class="i"><use href="#i-whatsapp"/></svg></a>
                    <a class="icon-btn" href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($share) }}" target="_blank" rel="noopener" aria-label="Facebook"><svg class="i"><use href="#i-facebook"/></svg></a>
                </div>
            </div>
        </aside>
    </div>
</section>
@if (count($more))
<section class="sec sec-alt">
    <div class="wrap">
        <div class="sec-head rv"><div><span class="eyebrow">{{ __('site.blog.eyebrow') }}</span><h2>{{ __('site.blog.more') }}</h2></div><a class="link-more" href="{{ Site::route('blog') }}">{{ __('site.common.view_all') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a></div>
        <div class="post-grid">@foreach ($more as $p)@include('site.partials.post-card', ['post' => $p])@endforeach</div>
    </div>
</section>
@endif
@include('site.partials.cta')
@endsection
