@php
    $user = auth()->user();
    $profile = $user->driverProfile;
    $photo = $profile?->getFirstMedia('photo');
    $problems = $this->problems();
    $docs = $this->documents();
    $editable = \App\Filament\Pages\MyProfile::editableFields();
@endphp

<x-filament-panels::page>
    <div class="rp-driver">
        <div class="rp-dv-hero rp-dv-profile">
            @if ($photo)
                <img src="{{ $photo->getTemporaryUrl(now()->addMinutes(10)) }}" alt="" class="rp-dv-avatar">
            @else
                <span class="rp-dv-avatar rp-dv-initials">{{ collect(explode(' ', $user->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
            @endif
            <div>
                <div class="rp-dv-car">{{ $profile?->legal_name ?: $user->name }}</div>
                <div class="rp-dv-meta">{{ $user->email }} · {{ $user->phone ?: 'no phone' }}</div>
                @if ($profile?->employee_ref)<div class="rp-dv-meta">Driver ID {{ $profile->employee_ref }}</div>@endif
            </div>
        </div>

        @if ($problems)
            <div class="rp-dv-alert">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                <div><strong>Please send the office</strong>@foreach ($problems as $p)<div>{{ $p }}</div>@endforeach</div>
            </div>
        @endif

        <div class="rp-dv-section">
            <div class="rp-dv-label">My documents</div>
            @forelse ($docs as $doc)
                <div class="rp-dv-docrow">
                    <div>
                        <div class="rp-dv-value">{{ $doc->typeLabel() }}</div>
                        <div class="rp-dv-meta">{{ $doc->maskedNumber() ?? 'No number' }}</div>
                    </div>
                    <span class="rp-dv-badge rp-dv-s-{{ $doc->expiryColor() }}">{{ $doc->expiryLabel() }}</span>
                    @if ($doc->path)
                        <a class="rp-dv-link" href="{{ $doc->viewUrl() }}" target="_blank" rel="noopener">View</a>
                    @endif
                </div>
            @empty
                <div class="rp-dv-meta">No documents on file yet. The office adds them.</div>
            @endforelse
        </div>

        @if ($editable)
            <form wire:submit="saveDetails" class="rp-dv-section">
                <div class="rp-dv-label">Update my details</div>
                {{ $this->detailsForm }}
                <x-filament::button type="submit" size="lg" class="rp-dv-bigbtn" style="margin-top:1rem">Save my details</x-filament::button>
            </form>
        @endif

        <form wire:submit="savePassword" class="rp-dv-section">
            <div class="rp-dv-label">Change my password</div>
            {{ $this->passwordForm }}
            <x-filament::button type="submit" size="lg" color="gray" class="rp-dv-bigbtn" style="margin-top:1rem">Change password</x-filament::button>
        </form>
    </div>
</x-filament-panels::page>
