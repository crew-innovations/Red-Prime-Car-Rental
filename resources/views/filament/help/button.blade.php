{{-- 20 Nov (item 12): the "? Help" button of a page and its guide in a side panel. --}}
<div x-data="{ open: false }" class="rp-help" x-on:keydown.escape.window="open = false">
    <button type="button" class="rp-help-btn" x-on:click="open = true" aria-haspopup="dialog" title="Help for this page">
        <span class="rp-help-q" aria-hidden="true">?</span><span class="rp-help-label">Help</span>
    </button>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="rp-help-layer" role="dialog" aria-modal="true" aria-label="Help: {{ $guide['title'] }}">
            <div class="rp-help-backdrop" x-on:click="open = false" x-show="open" x-transition.opacity></div>
            <aside class="rp-help-panel" x-show="open" x-transition:enter="rp-help-in" x-transition:enter-start="rp-help-from" x-transition:leave="rp-help-in" x-transition:leave-end="rp-help-from">
                <header class="rp-help-head">
                    <div>
                        <span class="rp-help-kicker">Help</span>
                        <h2>{{ $guide['title'] }}</h2>
                    </div>
                    <button type="button" class="rp-help-close" x-on:click="open = false" aria-label="Close help">&times;</button>
                </header>
                <div class="rp-help-body">
                    @include('filament.help.guide', ['guide' => $guide, 'portal' => ! $centre])
                </div>
                @if ($centre)
                    <footer class="rp-help-foot"><a href="{{ $centre }}">All help guides</a></footer>
                @endif
            </aside>
        </div>
    </template>
</div>
