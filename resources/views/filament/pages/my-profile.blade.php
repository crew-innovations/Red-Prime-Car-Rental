@php
    $user = auth()->user();
    $profile = $user->driverProfile;
    $photo = $profile?->getFirstMedia('photo');
    $problems = $this->problems();
    $docs = $this->documents();
    $checklist = $this->checklist();
    $editable = \App\Filament\Pages\MyProfile::editableFields();
@endphp

<x-filament-panels::page>
    <div class="rp-driver" dir="{{ \App\Support\Drivers\DriverLang::dir() }}">
        <div class="rp-dv-hero rp-dv-profile">
            @if ($photo)
                <img src="{{ $photo->getTemporaryUrl(now()->addMinutes(10)) }}" alt="" class="rp-dv-avatar">
            @else
                <span class="rp-dv-avatar rp-dv-initials">{{ collect(explode(' ', $user->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
            @endif
            <div>
                <div class="rp-dv-car">{{ $profile?->legal_name ?: $user->name }}</div>
                <div class="rp-dv-meta">{{ $user->email }} · {{ $user->phone ?: __('driver.no_phone') }}</div>
                @if ($profile?->employee_ref)<div class="rp-dv-meta">{{ __('driver.driver_id') }} {{ $profile->employee_ref }}</div>@endif
            </div>
        </div>

        @if ($problems)
            <div class="rp-dv-alert">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                <div><strong>{{ __('driver.send_office') }}</strong>@foreach ($problems as $p)<div>{{ $p }}</div>@endforeach</div>
            </div>
        @endif

        <div class="rp-dv-section">
            <div class="rp-dv-label">{{ __('driver.my_documents') }}</div>
            {{-- 20 Nov (item 8): what the office needs, and where each one stands. --}}
            @foreach ($checklist as $row)
                @php($c = ['approved' => 'success', 'pending' => 'warning'][$row['state']] ?? 'danger')
                <div class="rp-dv-docrow">
                    <div>
                        <div class="rp-dv-value">{{ $row['label'] }}</div>
                        @if ($row['state'] === 'rejected' && $row['doc']?->review_note)<div class="rp-dv-meta">{{ $row['doc']->review_note }}</div>@endif
                        @if ($row['state'] === 'approved' && $row['waiting'])<div class="rp-dv-meta">{{ __('driver.doc_new_waiting') }}</div>@endif
                    </div>
                    <span class="rp-dv-badge rp-dv-s-{{ $c }}">{{ __('driver.doc_state_'.$row['state']) }}</span>
                </div>
            @endforeach
            @foreach ($docs->reject(fn ($d) => isset($checklist[$d->type]) && $checklist[$d->type]['doc']?->id === $d->id && $d->status === 'approved') as $doc)
                <div class="rp-dv-docrow">
                    <div>
                        <div class="rp-dv-value">{{ $doc->typeLabel() }}</div>
                        <div class="rp-dv-meta">{{ $doc->maskedNumber() ?? __('driver.no_number') }} · {{ $doc->expiryLabel() }}</div>
                        @if ($doc->status === 'rejected' && $doc->review_note)<div class="rp-dv-meta">{{ $doc->review_note }}</div>@endif
                    </div>
                    <span class="rp-dv-badge rp-dv-s-{{ $doc->statusColor() }}">{{ __('driver.doc_status_'.($doc->status ?? 'approved')) }}</span>
                    @if ($doc->path)
                        <a class="rp-dv-link" href="{{ $doc->viewUrl() }}" target="_blank" rel="noopener">{{ __('driver.view') }}</a>
                    @endif
                </div>
            @endforeach
            @if ($docs->isEmpty() && $checklist === [])
                <div class="rp-dv-meta">{{ __('driver.no_documents') }}</div>
            @endif
        </div>

        <form wire:submit="saveDocument" class="rp-dv-section">
            <div class="rp-dv-label">{{ __('driver.upload_document') }}</div>
            <div class="rp-dv-meta" style="margin-bottom:.5rem">{{ __('driver.upload_document_help') }}</div>
            {{ $this->docForm }}
            <x-filament::button type="submit" size="lg" class="rp-dv-bigbtn" style="margin-top:1rem">{{ __('driver.send_document') }}</x-filament::button>
        </form>

        <div class="rp-dv-section">
            <div class="rp-dv-label">{{ __('driver.language') }}</div>
            <div class="rp-dv-actions-row">
                @foreach (\App\Support\Drivers\DriverLang::available() as $code => $name)
                    <button type="button" wire:click="setLanguage('{{ $code }}')" class="rp-dv-pill {{ app()->getLocale() === $code ? 'rp-dv-pill-primary' : '' }}">{{ $name }}</button>
                @endforeach
            </div>
            <a href="{{ \App\Filament\Pages\MyNotifications::getUrl() }}" class="rp-dv-link"><x-filament::icon icon="heroicon-m-bell-alert" class="h-4 w-4" /> {{ __('driver.my_notifications') }}</a>
        </div>

        @if ($editable)
            <form wire:submit="saveDetails" class="rp-dv-section">
                <div class="rp-dv-label">{{ __('driver.update_details') }}</div>
                {{ $this->detailsForm }}
                <x-filament::button type="submit" size="lg" class="rp-dv-bigbtn" style="margin-top:1rem">{{ __('driver.save_details') }}</x-filament::button>
            </form>
        @endif

        <form wire:submit="savePassword" class="rp-dv-section">
            <div class="rp-dv-label">{{ __('driver.change_password') }}</div>
            {{ $this->passwordForm }}
            <x-filament::button type="submit" size="lg" color="gray" class="rp-dv-bigbtn" style="margin-top:1rem">{{ __('driver.change_password_btn') }}</x-filament::button>
        </form>
    </div>
</x-filament-panels::page>
