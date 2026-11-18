{{--
    5 Nov ("Make it simple", B.8): the bell.
    Today / Earlier, a filter by type, one plain line per notice with its icon and colour,
    unread in bold, inline buttons (Accept, Approve, Open), "Mark all read", an optional
    sound for new leads (per person, on this device) and full screen on a phone.
--}}
@props([
    'notifications',
    'unreadNotificationsCount',
])

@php
    use Filament\Support\Enums\Alignment;

    $hasNotifications = $notifications->count();
    $isPaginated = $notifications instanceof \Illuminate\Contracts\Pagination\Paginator && $notifications->hasPages();
    $groupOf = function ($n): string {
        $event = data_get($n->data, 'viewData.event') ?? (data_get($n->data, 'viewData.lead_claim') || data_get($n->data, 'viewData.lead_taken') ? 'lead_broadcast' : 'other');
        $area = \App\Support\Notifications\Events::ALL[$event][1] ?? 'Other';

        return match ($area) {
            'Sales' => 'leads',
            'Bookings', 'Operations' => 'bookings',
            'Money' => 'money',
            'Drivers' => 'drivers',
            default => 'other',
        };
    };
    $list = $notifications instanceof \Illuminate\Contracts\Pagination\Paginator ? collect($notifications->items()) : collect($notifications);
    $sections = $list->groupBy(fn ($n) => $n->created_at?->isToday() ? 'Today' : 'Earlier');
    $newLeads = $list->filter(fn ($n) => $n->unread() && data_get($n->data, 'viewData.lead_claim'))->count();
@endphp

<x-filament::modal
    :alignment="$hasNotifications ? null : Alignment::Center"
    close-button
    :description="$hasNotifications ? null : 'New leads, payments and approvals appear here and on your phone.'"
    :heading="$hasNotifications ? null : 'Nothing new'"
    :icon="$hasNotifications ? null : 'heroicon-o-bell-slash'"
    :icon-color="$hasNotifications ? null : 'gray'"
    id="database-notifications"
    slide-over
    :sticky-header="$hasNotifications"
    width="md"
    class="rp-bell"
>
    @if ($hasNotifications)
        <x-slot name="header">
            <div
                x-data="{
                    filter: 'all',
                    sound: false,
                    init() {
                        try { this.sound = localStorage.getItem('rp-lead-sound') === '1' } catch (e) {}
                        this.$watch('filter', (f) => document.querySelectorAll('[data-rp-group]').forEach((el) => el.style.display = (f === 'all' || el.dataset.rpGroup === f) ? '' : 'none'));
                    },
                    toggleSound() {
                        this.sound = ! this.sound;
                        try { localStorage.setItem('rp-lead-sound', this.sound ? '1' : '0') } catch (e) {}
                    },
                }"
            >
                <x-filament-notifications::database.modal.heading :unread-notifications-count="$unreadNotificationsCount" />

                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                    @if ($unreadNotificationsCount)
                        <x-filament::link color="primary" tabindex="-1" tag="button" wire:click="markAllNotificationsAsRead">Mark all read</x-filament::link>
                    @endif
                    <x-filament::link color="gray" tabindex="-1" tag="button" x-on:click="toggleSound()">
                        <span x-text="sound ? 'Sound for new leads: on' : 'Sound for new leads: off'"></span>
                    </x-filament::link>
                    <x-filament::link color="danger" tabindex="-1" tag="button" wire:click="clearNotifications" x-on:click="close()">Clear all</x-filament::link>
                </div>

                <div class="rp-bell-filters" role="tablist">
                    @foreach (['all' => 'All', 'leads' => 'Leads', 'bookings' => 'Bookings', 'money' => 'Money', 'drivers' => 'Drivers', 'other' => 'Other'] as $key => $label)
                        <button type="button" role="tab" x-on:click="filter = '{{ $key }}'" x-bind:class="filter === '{{ $key }}' ? 'is-active' : ''">{{ $label }}</button>
                    @endforeach
                </div>

                {{-- A short sound when a new lead arrives (only if switched on, on this device). --}}
                <span hidden x-data="{
                    n: {{ (int) $newLeads }},
                    init() {
                        try {
                            const last = parseInt(sessionStorage.getItem('rp-new-leads') || '0', 10);
                            if (this.n > last && localStorage.getItem('rp-lead-sound') === '1') {
                                const c = new (window.AudioContext || window.webkitAudioContext)();
                                const o = c.createOscillator(); const g = c.createGain();
                                o.frequency.value = 880; g.gain.value = 0.08; o.connect(g); g.connect(c.destination);
                                o.start(); setTimeout(() => { o.stop(); c.close(); }, 180);
                            }
                            sessionStorage.setItem('rp-new-leads', String(this.n));
                        } catch (e) {}
                    },
                }"></span>
            </div>
        </x-slot>

        <div @class(['-mx-6 -mt-6', '-mb-6' => ! $isPaginated, 'border-b border-gray-200 dark:border-white/10' => $isPaginated])>
            @foreach ($sections as $section => $items)
                <div class="rp-bell-section">{{ $section }}</div>
                <div class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($items as $notification)
                        <div data-rp-group="{{ $groupOf($notification) }}"
                            @class([
                                'rp-bell-item relative',
                                'is-unread before:absolute before:start-0 before:h-full before:w-0.5 before:bg-primary-600 dark:before:bg-primary-500' => $notification->unread(),
                            ])>
                            {{ $this->getNotification($notification)->inline() }}
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        @if ($isPaginated)
            <x-slot name="footer">
                <x-filament::pagination :paginator="$notifications" />
            </x-slot>
        @endif
    @endif
</x-filament::modal>
