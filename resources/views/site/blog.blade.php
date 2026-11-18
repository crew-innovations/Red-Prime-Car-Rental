@extends('site.layout')
@php use App\Support\Site\Site; $first = $posts->first(); @endphp
@section('content')
@include('site.partials.phead', ['crumbs' => [[__('site.nav.blog'), null]], 'eyebrow' => __('site.blog.eyebrow'), 'title' => __('site.blog.title'), 'lead' => __('site.blog.lead'), 'bg' => Site::img('fleet-s580.webp')])
<section class="sec" style="padding-top:48px">
    <div class="wrap">
        @if ($first)
            @php $url = Site::route('post', ['slug' => $first->slug]); @endphp
            <article class="post-hero rv">
                <a class="post-hero-img" href="{{ $url }}" tabindex="-1" aria-hidden="true"><img src="{{ \App\Http\Controllers\Website\SiteController::postImage($first, 'large') }}" alt="" loading="eager"></a>
                <div class="post-hero-body">
                    <span class="card-tag">{{ $first->published_at?->locale(Site::locale())->translatedFormat('j M Y') }} · {{ __('site.blog.minutes', ['n' => $first->readMinutes()]) }}</span>
                    <h2><a href="{{ $url }}">{{ $first->local('title') }}</a></h2>
                    @if ($first->local('excerpt'))<p class="muted">{{ $first->local('excerpt') }}</p>@endif
                    <a class="btn btn-red mt-2" href="{{ $url }}">{{ __('site.blog.read') }} <svg class="i flip"><use href="#i-arrow"/></svg></a>
                </div>
            </article>
            @if ($posts->count() > 1)
                <div class="post-grid mt-4">@foreach ($posts->slice(1) as $post)@include('site.partials.post-card', ['post' => $post])@endforeach</div>
            @endif
        @else
            <div class="empty"><div class="ico"><svg class="i i-l"><use href="#i-info"/></svg></div><p>{{ __('site.blog.empty') }}</p></div>
        @endif
    </div>
</section>
@include('site.partials.cta')
@endsection
