@php $imgs = collect($images['gallery'] ?? [])->filter(fn ($i) => ! empty($i['url']))->values(); if ($imgs->isEmpty() && ! empty($images['cover']['url'])) { $imgs = collect([$images['cover']]); } @endphp
<div class="gallery" data-gallery data-images='@json($imgs->map(fn ($i) => ['src' => $i['large'] ?? $i['url'], 'alt' => $i['alt'] ?? $name])->all())'>
    <div class="gallery-main">
        @if ($imgs->isNotEmpty())
            <img src="{{ $imgs[0]['large'] ?? $imgs[0]['url'] }}" alt="{{ $imgs[0]['alt'] ?? $name }}" data-g-main fetchpriority="high">
            @if ($imgs->count() > 1)
                <button type="button" class="g-nav g-prev" data-g-prev aria-label="{{ __('site.common.previous') }}"><svg class="i flip"><use href="#i-chev-l"/></svg></button>
                <button type="button" class="g-nav g-next" data-g-next aria-label="{{ __('site.common.next') }}"><svg class="i flip"><use href="#i-chev-r"/></svg></button>
                <span class="badge badge-dark g-count" data-g-count>1 / {{ $imgs->count() }}</span>
            @endif
        @else
            <span class="ph" role="img" aria-label="{{ $name }}"><svg viewBox="0 0 120 60"><use href="#ph-{{ $kind }}"/></svg></span>
        @endif
        @if (! empty($badges))<div class="card-badges">{!! $badges !!}</div>@endif
    </div>
    @if ($imgs->count() > 1)
        <div class="thumbs">
            @foreach ($imgs as $i => $img)
                <button type="button" data-g-thumb="{{ $i }}" aria-current="{{ $i === 0 ? 'true' : 'false' }}" aria-label="{{ __('site.common.photo') }} {{ $i + 1 }}"><img src="{{ $img['thumb'] ?? $img['url'] }}" alt="" loading="lazy"></button>
            @endforeach
        </div>
    @endif
</div>
