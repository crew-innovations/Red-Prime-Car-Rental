<x-filament-widgets::widget>
    <section class="rp-glance rp-glance-{{ count($panels) }}" aria-label="At a glance">
        @foreach ($panels as $panel)
            <div class="rp-glance-panel">
                <h3 class="rp-glance-title">
                    <x-filament::icon :icon="$panel['icon']" class="rp-glance-icon" />
                    {{ $panel['title'] }}
                </h3>
                <ul class="rp-glance-list">
                    @foreach ($panel['rows'] as $r)
                        <li>
                            <a href="{{ $r['url'] }}" @class(['rp-glance-row', 'is-zero' => (string) $r['value'] === '0', 'is-'.$r['tone'] => $r['tone'] && (string) $r['value'] !== '0'])>
                                <span class="rp-glance-label">{{ $r['label'] }}</span>
                                <span class="rp-glance-value">
                                    @if ($r['unit'] === 'AED')<small>AED</small>@endif{{ $r['value'] }}@if ($r['unit'] && $r['unit'] !== 'AED')<small>{{ $r['unit'] }}</small>@endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </section>
</x-filament-widgets::widget>
