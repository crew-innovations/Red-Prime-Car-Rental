{{-- Offer from the CRM (Catalog::offers()). --}}
@php use App\Support\Site\Site; @endphp
<article class="offer rv tilt">
    <div class="offer-img"><img src="{{ $o['image_url'] }}" alt="" loading="lazy">@if (! empty($o['badge']))<span class="offer-badge">{{ $o['badge'] }}</span>@endif</div>
    <div class="offer-body">
        @if (! empty($o['car_name']))<span class="card-tag">{{ $o['car_name'] }}</span>@endif
        <h3>{{ $o['title'] }}</h3>
        @if (! empty($o['text']))<p class="muted">{{ $o['text'] }}</p>@endif
        @if (! empty($o['valid_until']))<small class="offer-until"><svg class="i i-s"><use href="#i-calendar"/></svg>{{ __('site.offers.valid_until', ['date' => \Illuminate\Support\Carbon::parse($o['valid_until'])->locale(Site::locale())->translatedFormat('j M Y')]) }}</small>@endif
        <div class="flex wrapf gap-1 mt-1">
            @if (! empty($o['link']))<a class="btn btn-red btn-s" href="{{ $o['link'] }}">{{ __('site.offers.view_car') }}</a>@endif
            <button type="button" class="btn btn-ghost btn-s" data-open-quote data-service="car" data-item="{{ $o['title'] }}">{{ __('site.offers.ask') }}</button>
        </div>
    </div>
</article>
