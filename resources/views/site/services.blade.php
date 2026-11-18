@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('content')
@include('site.partials.phead', ['crumbs' => [[__('site.nav.services'), null]], 'eyebrow' => __('site.services.eyebrow'), 'title' => __('site.services.title'), 'lead' => __('site.services.lead'), 'bg' => Site::img('photo-lambo-night.jpg')])
<section class="sec" style="padding-top:40px">
    <div class="wrap">
        @include('site.partials.service-cards', ['services' => $services])
    </div>
</section>
@include('site.partials.cta')
@endsection
