@extends('site.layout')
@php
    use App\Support\Site\Site;
    use App\Models\Setting;
    $title = $kind === 'terms' ? __('site.legal.terms_title') : __('site.legal.privacy_title');
@endphp
@section('content')
<section class="phead">
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ $title }}</span></nav>
        <h1 data-split>{{ $title }}</h1>
    </div>
</section>
<section class="sec" style="padding-top:36px">
    <div class="wrap legal">
        @if ($kind === 'terms')
            <p>{{ __('site.legal.terms_intro') }}</p>
            @if (filled(Setting::read('policy_cancellation')))<h2>{{ __('site.legal.cancellation') }}</h2><p>{{ Setting::read('policy_cancellation') }}</p>@endif
            @if (filled(Setting::read('policy_late_return')))<h2>{{ __('site.legal.late_return') }}</h2><p>{{ Setting::read('policy_late_return') }}</p>@endif
            @if (filled(Setting::read('quote_terms')))<h2>{{ __('site.car.terms') }}</h2><p>{{ Setting::read('quote_terms') }}</p>@endif
            <p>{{ __('site.legal.per_item') }}</p>
        @else
            @foreach (__('site.legal.privacy_text') as $para)<p>{{ $para }}</p>@endforeach
            <p>{{ collect([Site::phone(), Site::email()])->filter()->implode(' · ') }}</p>
        @endif
    </div>
</section>
@endsection
