@php
    use App\Support\Site\Site;
    use App\Support\Site\Catalog;
    $url = Site::route('car', ['slug' => $car['slug']]);
    $from = $car['_from'] ?? Catalog::carFrom($car);
    $p = $car['prices'] ?? [];
    $tags = collect([$car['body'] ?? null, $car['year'] ?? null, $car['fuel'] ?? null])->filter()->implode(' · ');
@endphp
<article class="card rv tilt" data-body="{{ $car['body'] ?? '' }}">
    <a class="card-media" href="{{ $url }}" tabindex="-1" aria-hidden="true">
        @include('site.partials.media', ['img' => $car['images']['cover'] ?? null, 'alt' => $car['name'], 'kind' => 'car'])
    </a>
    <div class="card-badges">
        @if (! empty($car['badge']))<span class="badge badge-gold">{{ $car['badge'] }}</span>@endif
        @if (! empty($car['with_driver']))<span class="badge badge-dark"><svg class="i i-s"><use href="#i-steering"/></svg>{{ __('site.common.chauffeur_available') }}</span>@endif
        @if (($car['_available'] ?? null) === true)<span class="badge badge-ok">{{ __('site.cars.free') }}</span>@endif
        @if (($car['_available'] ?? null) === false)<span class="badge badge-bad">{{ __('site.cars.busy') }}</span>@endif
    </div>
    <div class="card-body">
        <span class="card-tag">{{ $car['brand'] ?? '' }}@if ($tags) · {{ $tags }}@endif</span>
        <h3 class="card-title"><a href="{{ $url }}">{{ $car['name'] }}</a></h3>
        <div class="specs">
            @if (! empty($car['horsepower']))<span class="spec"><svg class="i"><use href="#i-gauge"/></svg>{{ __('site.common.hp', ['n' => $car['horsepower']]) }}</span>@endif
            @if (! empty($car['seats']))<span class="spec"><svg class="i"><use href="#i-users"/></svg>{{ __('site.common.seats', ['n' => $car['seats']]) }}</span>@endif
            @if (! empty($car['transmission']))<span class="spec"><svg class="i"><use href="#i-cog"/></svg>{{ ucfirst($car['transmission']) }}</span>@endif
        </div>
        <div class="card-foot">
            <div class="price">
                @if ($from)
                    <small>{{ __('site.common.from') }}</small>
                    <b>{{ Site::money($from) }} <span>{{ __('site.common.per_day') }}</span></b>
                    @if (! empty($p['weekly']))<em>{{ Site::money($p['weekly']) }} {{ __('site.common.per_week') }}</em>@endif
                @else
                    <b>{{ __('site.common.price_on_request') }}</b>
                @endif
            </div>
            <div class="card-actions">
                @if ($wa = Site::whatsapp(__('site.wa.hello', ['item' => $car['name']])))
                    <a class="icon-btn wa" href="{{ $wa }}" target="_blank" rel="noopener" aria-label="{{ __('site.common.ask_whatsapp') }}: {{ $car['name'] }}"><svg class="i"><use href="#i-whatsapp"/></svg></a>
                @endif
                <a class="btn btn-red btn-s" href="{{ $url }}">{{ __('site.common.book') }}</a>
            </div>
        </div>
    </div>
</article>
