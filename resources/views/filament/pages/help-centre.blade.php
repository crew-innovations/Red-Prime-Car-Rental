{{-- 20 Nov (item 12): every help guide in one place, with search. --}}
<x-filament-panels::page>
    <div x-data="{ q: '' }" class="rp-help-centre">
        <input type="search" x-model="q" class="rp-help-search" placeholder="Search the help (for example: deposit, fine, quotation, driver document)" aria-label="Search the help">
        <div class="rp-help-cards">
            @foreach ($this->guides() as $id => $guide)
                <details class="rp-help-card" id="{{ $id }}" data-text="{{ \Illuminate\Support\Str::lower(strip_tags(json_encode($guide))) }}"
                    x-show="! q || $el.dataset.text.includes(q.toLowerCase())" @if ($open === $id) open @endif>
                    <summary><strong>{{ $guide['title'] }}</strong><span>{{ $guide['for'] }}</span></summary>
                    @include('filament.help.guide', ['guide' => $guide + ['focus' => null, 'short' => []], 'portal' => $id === 'portal'])
                </details>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
