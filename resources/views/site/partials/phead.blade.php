{{-- Page header with breadcrumb, optional background picture. --}}
@php use App\Support\Site\Site; @endphp
<section class="phead {{ ! empty($bg) ? 'phead-img' : '' }}">
    @if (! empty($bg))<div class="phead-bg" aria-hidden="true" data-parallax><img src="{{ $bg }}" alt="" fetchpriority="high"></div>@endif
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}">
            <a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a>
            @foreach ($crumbs ?? [] as [$label, $url])<svg class="i flip"><use href="#i-chev-r"/></svg>@if ($url)<a href="{{ $url }}">{{ $label }}</a>@else<span>{{ $label }}</span>@endif @endforeach
        </nav>
        @if (! empty($eyebrow))<span class="eyebrow mt-2">{{ $eyebrow }}</span>@endif
        <h1 data-split>{{ $title }}</h1>
        @if (! empty($lead))<p>{{ $lead }}</p>@endif
        @if (! empty($actions)){!! $actions !!}@endif
    </div>
</section>
