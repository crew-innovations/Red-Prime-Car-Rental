<x-filament-panels::page>
    <div class="rp-acc">
        @php($profile = $this->profileGate())
        @if ($profile !== [])
            {{-- 19 Nov (item 7): missing details first. --}}
            <div class="rp-acc-card" style="border-left:4px solid #d7141a">
                <div class="rp-acc-label">Your details</div>
                <p class="rp-acc-muted" style="margin:.25rem 0 .75rem">We need a few details for your booking: {{ implode(', ', $profile) }}.</p>
                <form wire:submit="saveDetails">
                    {{ $this->detailsForm }}
                    <div style="margin-top:1rem"><x-filament::button type="submit">Save my details</x-filament::button></div>
                </form>
            </div>
        @endif
        @php($gate = $this->gate())
        @if ($gate !== [])
            {{-- 19 Nov: documents first, then the rest of the account. --}}
            <div class="rp-acc-card" style="border-left:4px solid #d4a62a">
                <div class="rp-acc-label">Before you can use your account</div>
                <p class="rp-acc-muted" style="margin:.25rem 0 .75rem">Please upload these documents. When our team has approved them, your bookings, payments and messages open here.</p>
                <table class="rp-acc-table">
                    @foreach ($gate as [$label, $text, $ok])
                        <tr>
                            <td>{{ $label }}</td>
                            <td class="r"><x-filament::badge :color="$ok ? 'success' : ($text === 'Missing' ? 'danger' : 'warning')">{{ $ok ? 'Approved' : ($text === \App\Enums\DocumentStatus::Received->getLabel() ? 'Being checked' : $text) }}</x-filament::badge></td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
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
