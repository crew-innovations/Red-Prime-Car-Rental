@extends('site.layout')
@php use App\Support\Site\Site; $ig = Site::socials()['instagram'] ?? null; @endphp
@section('content')
@include('site.partials.phead', ['crumbs' => [[__('site.nav.gallery'), null]], 'eyebrow' => __('site.gallery.eyebrow'), 'title' => __('site.gallery.title'), 'lead' => __('site.gallery.lead'), 'actions' => $ig ? '<div class="hero-cta"><a class="btn btn-red btn-l" href="'.e($ig).'" target="_blank" rel="noopener"><svg class="i"><use href="#i-instagram"/></svg>'.e(__('site.gallery.follow')).'</a></div>' : ''])
<section class="sec" style="padding-top:40px">
    <div class="wrap">
        <div class="insta-grid insta-grid-l" data-gallery data-images='@json(collect($photos)->map(fn ($p) => ['src' => $p, 'alt' => Site::company()])->all())'>
            @foreach ($photos as $i => $src)
                <button type="button" class="insta rv" data-g-open="{{ $i }}" aria-label="{{ __('site.common.photo') }} {{ $i + 1 }}"><img src="{{ $src }}" alt="" loading="lazy"></button>
            @endforeach
        </div>
    </div>
</section>
@include('site.partials.cta')
@endsection
