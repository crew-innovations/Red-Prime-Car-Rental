<x-filament-panels::page>
    <div class="rp-stack">
        {{ $this->form }}

        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
            <x-filament::button wire:click="readFile" icon="heroicon-m-document-magnifying-glass" :color="$headings ? 'gray' : 'primary'">{{ $headings ? 'Read the file again' : 'Read the file' }}</x-filament::button>
            @if ($headings)
                <x-filament::button wire:click="check" color="gray" icon="heroicon-m-arrow-path">Check again</x-filament::button>
            @endif
        </div>

        @if ($preview)
            <x-filament::section heading="3. Check and import" icon="heroicon-o-check-badge">
                <div class="rp-imp-counts">
                    <div><strong>{{ $preview['rows'] }}</strong><span>rows in the file</span></div>
                    <div class="ok"><strong>{{ $preview['new'] }}</strong><span>new</span></div>
                    <div><strong>{{ $preview['duplicate'] }}</strong><span>already imported (skipped)</span></div>
                    <div class="{{ $preview['errors'] ? 'bad' : '' }}"><strong>{{ $preview['errors'] }}</strong><span>rows not read</span></div>
                </div>
                @if ($preview['missing'])
                    <p class="rp-warn">Pick the columns for: {{ implode(', ', $preview['missing']) }}.</p>
                @endif
                @foreach ($preview['error_lines'] as $e)
                    <div class="rp-muted" style="font-size:.85rem">{{ $e }}</div>
                @endforeach
                @if ($preview['sample'])
                    <div class="rp-table-wrap" style="margin-top:.8rem">
                        <table class="rp-simple-table">
                            <thead><tr>@foreach (array_keys($preview['sample'][0]) as $k)<th>{{ str_replace('_', ' ', $k) }}</th>@endforeach</tr></thead>
                            <tbody>@foreach ($preview['sample'] as $r)<tr>@foreach ($r as $v)<td>{{ $v }}</td>@endforeach</tr>@endforeach</tbody>
                        </table>
                    </div>
                @endif
                <div style="margin-top:1rem">
                    <x-filament::button wire:click="import" wire:loading.attr="disabled" icon="heroicon-m-arrow-down-tray" :disabled="(bool) $preview['missing'] || ! $preview['new']">
                        Import {{ $preview['new'] }} {{ \Illuminate\Support\Str::plural('row', $preview['new']) }} and match them
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif

        @php($batches = $this->batches())
        @if ($batches->isNotEmpty())
            <x-filament::section heading="Recent imports" icon="heroicon-o-clock" collapsible>
                <div class="rp-table-wrap">
                    <table class="rp-simple-table">
                        <thead><tr><th>When</th><th>File</th><th>What</th><th>New</th><th>Matched</th><th>Skipped</th><th>Not read</th><th>By</th></tr></thead>
                        <tbody>
                            @foreach ($batches as $b)
                                <tr><td>{{ $b->created_at->format('d M H:i') }}</td><td>{{ $b->file_name }}</td><td>{{ $b->kind === 'fines' ? 'Fines · '.$b->source : (\App\Models\TollTrip::TYPES[$b->source] ?? $b->source) }}</td>
                                    <td>{{ $b->rows_new }}</td><td>{{ $b->rows_matched }}</td><td>{{ $b->rows_duplicate }}</td><td>{{ $b->rows_error }}</td><td>{{ $b->creator?->name }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
