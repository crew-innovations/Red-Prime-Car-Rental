<x-filament-widgets::widget>
    <section class="rp-work" aria-label="My work">
        <header class="rp-work-head">
            <div>
                <h3 class="rp-work-title">My work</h3>
                <p class="rp-work-sub">{{ $steps ? 'Your next steps, from enquiry to paid booking.' : 'Nothing waiting for you right now.' }}</p>
            </div>
            <div class="rp-work-actions">
                @foreach ($actions as $a)
                    @if (rescue(fn () => $a['url'], null, false))
                        <a href="{{ $a['url'] }}" class="rp-cbtn"><x-filament::icon :icon="$a['icon']" class="rp-cbtn-icon" />{{ $a['label'] }}</a>
                    @endif
                @endforeach
            </div>
        </header>

        @if ($steps)
            <div class="rp-work-grid">
                @foreach ($steps as $step)
                    <div @class(['rp-work-step', 'is-'.$step['tone'] => $step['tone']])>
                        <div class="rp-work-step-head">
                            <span class="rp-work-step-title">{{ $step['title'] }} <b>{{ $step['count'] }}</b></span>
                            <span class="rp-work-step-hint">{{ $step['hint'] }}</span>
                        </div>
                        <ul>
                            @foreach ($step['items'] as $item)
                                <li><a href="{{ $item['url'] }}"><strong>{{ $item['title'] }}</strong><span>{{ $item['sub'] }}</span></a></li>
                            @endforeach
                        </ul>
                        <a href="{{ $step['more'] }}" class="rp-work-more">See all</a>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($done)
            <p class="rp-work-done">Nothing waiting: {{ implode(' · ', $done) }}</p>
        @endif
    </section>
</x-filament-widgets::widget>
