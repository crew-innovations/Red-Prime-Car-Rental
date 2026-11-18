@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('content')
@include('site.partials.phead', ['crumbs' => [[__('site.nav.cars'), Site::route('cars')], [__('site.nav.brands'), null]], 'eyebrow' => __('site.brands.eyebrow'), 'title' => __('site.brands.title'), 'lead' => __('site.brands.lead'), 'bg' => Site::img('fleet-garage.webp')])
<section class="sec" style="padding-top:40px">
    <div class="wrap">
        @include('site.partials.brand-tiles', ['brands' => $brands])
        <p class="muted small mt-4" style="text-align:center">{{ __('site.brands.note') }}</p>
    </div>
</section>
@include('site.partials.cta')
@endsection
