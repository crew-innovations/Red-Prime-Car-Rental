{{-- Cover image or a neutral placeholder. $img = API image array or null, $kind = car|yacht --}}
@if (! empty($img['url']))
    <img src="{{ $img[$size ?? 'thumb'] ?? $img['url'] }}" alt="{{ $alt ?? ($img['alt'] ?? '') }}" loading="{{ $eager ?? false ? 'eager' : 'lazy' }}" decoding="async" @if ($eager ?? false) fetchpriority="high" @endif>
@else
    <span class="ph" role="img" aria-label="{{ $alt ?? '' }}"><svg viewBox="0 0 120 60"><use href="#ph-{{ $kind ?? 'car' }}"/></svg></span>
@endif
