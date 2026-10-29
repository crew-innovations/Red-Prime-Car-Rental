{{-- The standard edit page, with the supplier's balance on top (Finance only). --}}
@php
    $b = \App\Support\Access::allows('manage-finance') ? $this->balance() : null;
@endphp
<x-filament-panels::page
    @class([
        'fi-resource-edit-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'fi-resource-record-' . $record->getKey(),
    ])
>
    @if ($b)
        <div class="rp-rep-cards" style="margin-bottom:1rem">
            <div class="rp-rep-card rp-rep-main"><span>We owe</span><strong>AED {{ number_format($b['balance'], 2) }}</strong><em>Opening + approved bills − payments</em></div>
            <div class="rp-rep-card"><span>Opening balance</span><strong>AED {{ number_format($b['opening'], 2) }}</strong><em>Before the CRM</em></div>
            <div class="rp-rep-card"><span>Bills</span><strong>AED {{ number_format($b['bills'], 2) }}</strong><em>{{ $b['waiting_approval'] > 0 ? 'Plus AED '.number_format($b['waiting_approval'], 2).' waiting for approval' : 'Approved, not reversed' }}</em></div>
            <div class="rp-rep-card"><span>Paid</span><strong>AED {{ number_format($b['payments'], 2) }}</strong><em>Payments not reversed</em></div>
        </div>
    @endif
    @capture($form)
        <x-filament-panels::form
            id="form"
            :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
            wire:submit="save"
        >
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>
    @endcapture

    @php
        $relationManagers = $this->getRelationManagers();
        $hasCombinedRelationManagerTabsWithContent = $this->hasCombinedRelationManagerTabsWithContent();
    @endphp

    @if ((! $hasCombinedRelationManagerTabsWithContent) || (! count($relationManagers)))
        {{ $form() }}
    @endif

    @if (count($relationManagers))
        <x-filament-panels::resources.relation-managers
            :active-locale="isset($activeLocale) ? $activeLocale : null"
            :active-manager="$this->activeRelationManager ?? ($hasCombinedRelationManagerTabsWithContent ? null : array_key_first($relationManagers))"
            :content-tab-label="$this->getContentTabLabel()"
            :content-tab-icon="$this->getContentTabIcon()"
            :content-tab-position="$this->getContentTabPosition()"
            :managers="$relationManagers"
            :owner-record="$record"
            :page-class="static::class"
        >
            @if ($hasCombinedRelationManagerTabsWithContent)
                <x-slot name="content">
                    {{ $form() }}
                </x-slot>
            @endif
        </x-filament-panels::resources.relation-managers>
    @endif

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>
