@php
    use App\Support\Site\Site;
    $isPackage = ($item['type'] ?? null) === 'package';
    $url = Site::route($isPackage ? 'package' : 'trip', ['slug' => $item['slug']]);
    $amount = $isPackage ? ($item['price']['from_amount'] ?? null) : ($item['price']['amount'] ?? null);
    $unit = $item['price']['unit'] ?? 'per_person';
    $next = $item['next_departure'] ?? null;
@endphp
<article class="card rv">
    <a class="card-media" href="{{ $url }}" tabindex="-1" aria-hidden="true">
        @include('site.partials.media', ['img' => $item['images']['cover'] ?? null, 'alt' => $item['name'], 'kind' => 'yacht'])
    </a>
    <div class="card-badges">
        @if (! empty($item['badge']))<span class="badge badge-gold">{{ $item['badge'] }}</span>@endif
        @if ($next && isset($next['seats_left']))<span class="badge badge-dark">{{ trans_choice('site.trips.seats_left', $next['seats_left'], ['count' => $next['seats_left']]) }}</span>@endif
    </div>
    <div class="card-body">
        <span class="card-tag">{{ $isPackage ? ($item['duration']['label'] ?? ($item['category']['name'] ?? '')) : collect([$item['departure_marina'] ?? ($item['location'] ?? null), ! empty($item['duration_minutes']) ? __('site.common.minutes', ['n' => $item['duration_minutes']]) : null])->filter()->implode(' · ') }}</span>
        <h3 class="card-title"><a href="{{ $url }}">{{ $item['name'] }}</a></h3>
        @if (! empty($item['summary']))<p class="muted small">{{ \Illuminate\Support\Str::limit($item['summary'], 110) }}</p>@endif
        @if ($next)
            <div class="specs"><span class="spec"><svg class="i"><use href="#i-calendar"/></svg>{{ __('site.trips.next') }}: {{ \Illuminate\Support\Carbon::parse($next['date'] ?? $next['start_date'])->translatedFormat('D j M') }}@if (! empty($next['start_time'])) · {{ $next['start_time'] }}@endif</span></div>
        @endif
        <div class="card-foot">
            <div class="price">
                @if ($amount)
                    <small>{{ __('site.common.from') }}</small>
                    <b>{{ Site::money($amount) }} <span>{{ __('site.common.'.$unit) }}</span></b>
                @else
                    <b>{{ __('site.common.price_on_request') }}</b>
                @endif
            </div>
            <a class="btn btn-red btn-s" href="{{ $url }}">{{ __('site.common.book') }}</a>
        </div>
    </div>
</article>
