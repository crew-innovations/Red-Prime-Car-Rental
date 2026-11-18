{{-- Brand logo tiles. $brands = Catalog::brands() rows: name, slug, count, logo (null = name tile). --}}
<div class="logo-grid">
    @foreach ($brands as $b)
        <a class="logo-tile rv" href="{{ \App\Support\Site\Site::route('brand', ['slug' => $b['slug']]) }}">
            <span class="logo-box">
                @if ($b['logo'])<img src="{{ $b['logo'] }}" alt="{{ $b['name'] }}" loading="lazy">@else<span class="logo-word">{{ $b['name'] }}</span>@endif
            </span>
            <span class="logo-meta"><b>{{ $b['name'] }}</b><small>{{ $b['count'] ? trans_choice('site.brands.cars', $b['count'], ['count' => $b['count']]) : __('site.brands.on_request') }}</small></span>
        </a>
    @endforeach
</div>
