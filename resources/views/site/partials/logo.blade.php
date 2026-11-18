{{-- The RED PRIME logo (Bilal, 29 Oct). Dark and light versions swap with the theme. --}}
@php $big = ($size ?? 's') === 'l'; @endphp
<span class="brand-logo {{ $big ? 'brand-logo-l' : '' }}">
    <img class="logo-on-dark" src="{{ asset('images/brand/logo-dark'.($big ? '' : '-s').'.webp') }}" alt="{{ \App\Support\Site\Site::company() }}" width="{{ $big ? 520 : 346 }}" height="{{ $big ? 295 : 196 }}" @if ($big) loading="lazy" @endif decoding="async">
    <img class="logo-on-light" src="{{ asset('images/brand/logo-light'.($big ? '' : '-s').'.webp') }}" alt="" aria-hidden="true" width="{{ $big ? 520 : 346 }}" height="{{ $big ? 295 : 196 }}" @if ($big) loading="lazy" @endif decoding="async">
</span>
