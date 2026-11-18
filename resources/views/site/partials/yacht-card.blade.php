@php
    use App\Support\Site\Site;
    $url = Site::route('yacht', ['slug' => $yacht['slug']]);
    $p = $yacht['prices'] ?? [];
@endphp
<article class="card rv">
    <a class="card-media" href="{{ $url }}" tabindex="-1" aria-hidden="true">
        @include('site.partials.media', ['img' => $yacht['images']['cover'] ?? null, 'alt' => $yacht['name'], 'kind' => 'yacht'])
    </a>
    <div class="card-badges">
        @if (! empty($yacht['badge']))<span class="badge badge-gold">{{ $yacht['badge'] }}</span>@endif
        @if (! empty($yacht['captain_included']))<span class="badge badge-dark"><svg class="i i-s"><use href="#i-anchor"/></svg>{{ __('site.common.captain_included') }}</span>@endif
    </div>
    <div class="card-body">
        <span class="card-tag">{{ collect([$yacht['marina'] ?? null, ! empty($yacht['length_ft']) ? __('site.common.ft', ['n' => (float) $yacht['length_ft']]) : null])->filter()->implode(' · ') }}</span>
        <h3 class="card-title"><a href="{{ $url }}">{{ $yacht['name'] }}</a></h3>
        <div class="specs">
            @if (! empty($yacht['guests']))<span class="spec"><svg class="i"><use href="#i-users"/></svg>{{ __('site.common.guests', ['n' => $yacht['guests']]) }}</span>@endif
            @if (! empty($yacht['cabins']))<span class="spec"><svg class="i"><use href="#i-door"/></svg>{{ __('site.common.cabins', ['n' => $yacht['cabins']]) }}</span>@endif
            @if (! empty($yacht['minimum_hours']))<span class="spec"><svg class="i"><use href="#i-clock"/></svg>{{ __('site.yachts.min_hours', ['n' => $yacht['minimum_hours']]) }}</span>@endif
        </div>
        <div class="card-foot">
            <div class="price">
                @if (! empty($p['hourly']))
                    <small>{{ __('site.common.from') }}</small>
                    <b>{{ Site::money($p['hourly']) }} <span>{{ __('site.common.per_hour') }}</span></b>
                    @if (! empty($p['full_day']))<em>{{ __('site.yachts.full_day') }} {{ Site::money($p['full_day']) }}</em>@endif
                @elseif (! empty($yacht['_from']))
                    <small>{{ __('site.common.from') }}</small><b>{{ Site::money($yacht['_from']) }}</b>
                @else
                    <b>{{ __('site.common.price_on_request') }}</b>
                @endif
            </div>
            <div class="card-actions">
                @if ($wa = Site::whatsapp(__('site.wa.hello', ['item' => $yacht['name']])))
                    <a class="icon-btn wa" href="{{ $wa }}" target="_blank" rel="noopener" aria-label="{{ __('site.common.ask_whatsapp') }}: {{ $yacht['name'] }}"><svg class="i"><use href="#i-whatsapp"/></svg></a>
                @endif
                <a class="btn btn-red btn-s" href="{{ $url }}">{{ __('site.common.book') }}</a>
            </div>
        </div>
    </div>
</article>
