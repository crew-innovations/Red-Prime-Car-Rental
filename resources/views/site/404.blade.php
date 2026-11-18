@extends('site.layout')
@php use App\Support\Site\Site; @endphp
@section('content')
<section class="wrap err-page">
    <div>
        <b class="accent">404</b>
        <h1 class="mt-2" style="font-size:32px">{{ __('site.errors.not_found') }}</h1>
        <p class="muted mt-1">{{ __('site.errors.not_found_text') }}</p>
        <div class="flex wrapf gap-1 mt-3" style="justify-content:center">
            <a class="btn btn-red" href="{{ Site::has('cars') ? Site::route('cars') : Site::route('home') }}">{{ __('site.home.cta_fleet') }}</a>
            <a class="btn btn-ghost" href="{{ Site::route('home') }}">{{ __('site.nav.home') }}</a>
        </div>
    </div>
</section>
@endsection
