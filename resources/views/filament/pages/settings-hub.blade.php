<x-filament-panels::page>
    <div class="rp-settings-hub">
        @foreach ($this->cards() as $card)
            <section class="rp-settings-card">
                <h2>{{ $card['title'] }}</h2>
                <p>{{ $card['about'] }}</p>
                <ul>
                    @foreach ($card['links'] as $link)
                        <li>
                            <a href="{{ $link['url'] }}">
                                <x-filament::icon :icon="$link['icon']" class="h-5 w-5" />
                                <span>{{ $link['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
