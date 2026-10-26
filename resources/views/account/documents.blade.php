<x-filament-panels::page>
    <div class="rp-acc">
        <div class="rp-acc-card">
            <div class="rp-acc-label">Your documents</div>
            <table class="rp-acc-table">
                @forelse ($this->documents() as $doc)
                    <tr>
                        <td>{{ \App\Support\Options::documentLabel($doc->type) }}<br><span class="rp-acc-muted">{{ $doc->created_at->format('d M Y') }}{{ $doc->expires_on ? ' · expires '.$doc->expires_on->format('d M Y') : '' }}</span>
                            @if ($doc->review_note && in_array($doc->status, [\App\Enums\DocumentStatus::Rejected, \App\Enums\DocumentStatus::Replacement], true))<br><span class="rp-acc-muted">{{ $doc->review_note }}</span>@endif
                        </td>
                        <td class="r">
                            <x-filament::badge :color="$doc->status->getColor()">{{ $doc->status === \App\Enums\DocumentStatus::Received ? 'Being checked' : $doc->status->getLabel() }}</x-filament::badge>
                            <div><a class="rp-acc-link" href="{{ $doc->viewUrl() }}" target="_blank" rel="noopener">View</a></div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="rp-acc-muted">No documents yet.</td></tr>
                @endforelse
            </table>
        </div>

        @if (\App\Filament\Account\Pages\Documents::canUpload())
            <form wire:submit="save" class="rp-acc-card">
                <div class="rp-acc-label">Upload a document</div>
                {{ $this->form }}
                <x-filament::button type="submit" size="lg" style="margin-top:1rem;width:100%">Upload</x-filament::button>
            </form>
        @endif
    </div>
</x-filament-panels::page>
