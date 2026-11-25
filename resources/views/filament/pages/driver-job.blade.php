@php
    $job = $this->record;
    $b = $this->booking();
    $car = $b?->bookable;
    $phone = $this->customerPhone();
    $wa = $this->whatsappUrl();
    $map = $this->mapUrl();
    $nav = $job->isOpen() ? $job->navigateUrl() : null;
    $steps = $this->nextSteps();
    $check = $job->inspection ?? [];
    $cfg = $this->phoneConfig();
    $canLate = $job->isOpen() && $job->status !== 'waiting';
@endphp

<x-filament-panels::page>
    <div class="rp-driver" dir="{{ \App\Support\Drivers\DriverLang::dir() }}"
         x-data="{ late: false }"
         x-init="window.RPDriver && RPDriver.track(@js($cfg))">
        <div class="rp-dv-hero">
            <div class="rp-dv-card-top">
                <span class="rp-dv-badge rp-dv-{{ $job->type }}">{{ __('driver.type_'.$job->typeKey()) }}</span>
                <span class="rp-dv-badge rp-dv-s-{{ $job->statusColor() }}">{{ __('driver.status_'.$job->status) }}</span>
            </div>
            <div class="rp-dv-when">{{ $job->scheduled_at->translatedFormat('D d M') }} · <strong>{{ $job->scheduled_at->format('H:i') }}</strong>@if ($job->ends_at) – <strong>{{ $job->ends_at->format('H:i') }}</strong>@endif</div>
            <div class="rp-dv-car">{{ $car?->name ?? match ($job->type) { 'charter' => __('driver.yacht'), 'transfer' => ($b?->vehicleCategory?->name ?? __('driver.car_tba')), default => __('driver.car') } }}@if ($car?->registration_number) <span class="rp-dv-plate">{{ $car->registration_number }}</span>@endif</div>
            @if ($job->type === 'charter')
                <div class="rp-dv-meta">{{ $b?->guests ? __('driver.n_guests', ['n' => $b->guests]) : __('driver.guests_not_set') }}@if ($car?->max_guests) · max {{ $car->max_guests }}@endif @if ($b?->captain) · {{ __('driver.captain') }} {{ $b->captain->name }}@endif @if ($b?->crew_ids) · {{ __('driver.crew') }} {{ $b->crewMembers()->pluck('name')->implode(', ') }}@endif</div>
            @endif
            @if ($job->late_minutes && $job->isOpen())
                <div class="rp-dv-meta rp-dv-late">{{ __('driver.late_reported', ['n' => $job->late_minutes]) }}</div>
            @endif
        </div>

        {{-- 6 Nov (limousine L3): free waiting clock after "Arrived". --}}
            @if ($job->type === 'transfer' && $job->status === 'arrived' && ! $b?->delivered_at && ! empty($job->inspection['arrived_at']))
                @php $freeUntil = \Illuminate\Support\Carbon::parse($job->inspection['arrived_at'])->max($b->start_at)->addMinutes(\App\Support\Transfers\Tracking::freeMinutes($b)); @endphp
                <div class="rp-dv-section rp-dv-note">{{ __('driver.free_wait_until', ['time' => $freeUntil->format('H:i')]) }}</div>
            @endif
        @if ($job->status === 'declined')
            <div class="rp-dv-section rp-dv-note">
                <div class="rp-dv-value">{{ __('driver.declined_note', ['reason' => $job->decline_reason]) }}</div>
            </div>
        @endif

        @if ($cfg['share'])
            <div class="rp-dv-share" id="rp-share">
                <span class="rp-dv-dot"></span> <span data-ok>{{ __('driver.location_on') }}</span>
                <span data-denied hidden>{{ __('driver.location_denied') }}</span>
            </div>
        @endif

        @if ($nav)
            <a class="rp-dv-navbtn" href="{{ $nav }}" target="_blank" rel="noopener">
                <x-filament::icon icon="heroicon-m-map" class="h-5 w-5" /> {{ __('driver.navigate') }}
            </a>
        @endif

        @if ($job->type === 'transfer' && $b)
            <div class="rp-dv-section">
                <div class="rp-dv-label">{{ $b->transferService?->name ?? __('driver.type_transfer') }}</div>
                <div class="rp-dv-meta">{{ collect([$b->guests ? __('driver.n_passengers', ['n' => $b->guests]) : null, $b->luggage ? __('driver.n_bags', ['n' => $b->luggage]) : null, $b->child_seats ? __('driver.n_child_seats', ['n' => $b->child_seats]) : null])->filter()->implode(' · ') ?: __('driver.passengers_not_set') }}</div>
                @if ($b->flight_number)
                    <div class="rp-dv-value">{{ __('driver.flight') }} {{ $b->flight_number }}{{ $b->airline ? ' · '.$b->airline : '' }}{{ $b->terminal ? ' · '.__('driver.terminal').' '.$b->terminal : '' }}</div>
                @endif
                @if ($b->special_requests)<div class="rp-dv-meta" style="white-space:pre-line">{{ $b->special_requests }}</div>@endif
            </div>
            <div class="rp-dv-section">
                <div class="rp-dv-label">{{ __('driver.pick_up') }}</div>
                <div class="rp-dv-value">{{ $b->pickup_location ?: __('driver.address_missing') }}</div>
                @foreach ((array) $b->stops as $n => $stop)
                    @if (! empty($stop['address']))<div class="rp-dv-meta">{{ __('driver.stop') }} {{ $n + 1 }}: {{ $stop['address'] }}</div>@endif
                @endforeach
                <div class="rp-dv-label" style="margin-top:.6rem">{{ __('driver.drop_off') }}</div>
                <div class="rp-dv-value">{{ $b->dropoff_location ?: ($b->journey_type === 'hourly' ? __('driver.by_the_hour') : __('driver.not_set')) }}</div>
                @if ($b->route_km)<div class="rp-dv-meta">{{ __('driver.about_km', ['km' => rtrim(rtrim(number_format((float) $b->route_km, 1), '0'), '.'), 'min' => $b->route_minutes]) }}</div>@endif
                @if ($b->return_at)<div class="rp-dv-meta">{{ __('driver.return_trip') }}: {{ $b->return_at->translatedFormat('D d M H:i') }}</div>@endif
            </div>
        @else
            <div class="rp-dv-section">
                <div class="rp-dv-label">{{ match ($job->type) { 'delivery' => __('driver.deliver_to'), 'charter' => __('driver.marina'), default => __('driver.collect_from') } }}</div>
                <div class="rp-dv-value">{{ $job->address ?: __('driver.address_missing') }}</div>
                @if ($map && ! $nav)
                    <a class="rp-dv-link" href="{{ $map }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-m-map" class="h-4 w-4" /> {{ __('driver.open_in_maps') }}</a>
                @endif
            </div>
        @endif

        <div class="rp-dv-section">
            <div class="rp-dv-label">{{ __('driver.customer') }}</div>
            <div class="rp-dv-value">{{ $b?->customer?->name ?? '-' }}</div>
            @if ($phone)
                <div class="rp-dv-actions-row">
                    <a class="rp-dv-pill" href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}"><x-filament::icon icon="heroicon-m-phone" class="h-4 w-4" /> {{ __('driver.call') }}</a>
                    @if ($wa)
                        <a class="rp-dv-pill" href="{{ $wa }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-4 w-4" /> WhatsApp</a>
                    @endif
                </div>
            @endif
            <div class="rp-dv-meta" dir="ltr">{{ $b?->reference }} · {{ $b?->start_at?->format('d M H:i') }} → {{ $b?->end_at?->format('d M H:i') }}</div>
        </div>

        @if ($this->showInstructions() && filled($b?->driver_instructions))
            <div class="rp-dv-section rp-dv-note">
                <div class="rp-dv-label">{{ __('driver.instructions') }}</div>
                <div class="rp-dv-value" style="white-space:pre-line">{{ $b->driver_instructions }}</div>
            </div>
        @endif

        @if ($job->status === 'waiting')
            <div class="rp-dv-section rp-dv-note">
                <div class="rp-dv-label">{{ __('driver.waiting_office') }}</div>
                <div class="rp-dv-value">{{ __('driver.waiting_office_text') }}</div>
            </div>
        @elseif ($job->type === 'delivery' && ! empty($check['rejected']) && $job->isOpen())
            <div class="rp-dv-section rp-dv-note">
                <div class="rp-dv-label">{{ __('driver.office_said') }}</div>
                <div class="rp-dv-value">{{ $check['rejected'] }}</div>
            </div>
        @endif

        @if ($job->type === 'collection' && $b && ($handover = $b->getMedia('delivery')->sortByDesc('id')->take(8))->isNotEmpty())
            <div class="rp-dv-section">
                <div class="rp-dv-label">{{ __('driver.handover_photos') }}</div>
                <div class="rp-dv-photos">
                    @foreach ($handover as $m)
                        <a href="{{ $m->getTemporaryUrl(now()->addMinutes(30)) }}" target="_blank" rel="noopener"><img src="{{ $m->getTemporaryUrl(now()->addMinutes(30)) }}" alt="" loading="lazy"></a>
                    @endforeach
                </div>
                @if ($b->delivery_km || $b->delivery_fuel || $b->delivery_fuel_8 !== null)
                    <div class="rp-dv-meta">{{ __('driver.at_handover') }}: {{ $b->delivery_km ? number_format($b->delivery_km).' km' : '' }}{{ ($b->delivery_fuel || $b->delivery_fuel_8 !== null) ? ' · '.\App\Support\Fuel\Fuel::label($b->delivery_fuel_8 ?? $b->delivery_fuel) : '' }}</div>
                @endif
            </div>
        @endif

        @if ($job->isOpen())
            @php($list = in_array($job->type, ['charter', 'transfer'], true) ? [] : (array) \App\Models\Setting::read($job->type === 'delivery' ? 'handover_checklist' : 'return_checklist', []))
            @if ($list)
                <div class="rp-dv-section">
                    <div class="rp-dv-label">{{ __('driver.you_will_check') }}</div>
                    <ul class="rp-dv-checklist">
                        @foreach ($list as $item)<li>{{ $item }}</li>@endforeach
                    </ul>
                </div>
            @endif
        @endif

        @if ($job->status === 'done' && $check)
            <div class="rp-dv-section">
                <div class="rp-dv-label">{{ __('driver.recorded') }} {{ $job->done_at?->format('d M H:i') }}</div>
                <div class="rp-dv-value">
                    @if (isset($check['km'])){{ number_format((int) $check['km']) }} km · @endif
                    @if (isset($check['fuel']) && $check['fuel'] !== ''){{ __('driver.fuel_level') }} {{ \App\Support\Fuel\Fuel::label($check['fuel']) }} · @endif
                    @if (! empty($check['condition'])){{ \App\Models\Booking::CONDITIONS[$check['condition']] ?? $check['condition'] }} · @endif
                    {{ __('driver.n_photos', ['n' => (int) ($check['photos'] ?? 0)]) }}@if (! empty($check['signed'])) · ✓ {{ __('driver.signed') }}@endif
                </div>
                @if (! empty($check['notes']))<div class="rp-dv-meta">{{ $check['notes'] }}</div>@endif
            </div>
        @endif

        <div class="rp-dv-buttons">
            @foreach ($steps as $step)
                <x-filament::button size="xl" :color="$step === 'acknowledged' ? 'primary' : 'gray'" class="rp-dv-bigbtn"
                    x-on:click="RPDriver.act({{ $job->id }}, '{{ $step }}').then(() => $wire.$refresh())">
                    {{ __('driver.step_'.$step) }}
                </x-filament::button>
            @endforeach
            @foreach ([$this->signContractAction, $this->handoverAction, $this->collectAction, $this->startCharterAction, $this->finishCharterAction] as $action)
                @if ($action->isVisible())
                    {{ $action }}
                @endif
            @endforeach

            @if ($canLate)
                <x-filament::button size="lg" color="warning" outlined class="rp-dv-bigbtn" x-on:click="late = ! late">
                    <x-filament::icon icon="heroicon-m-clock" class="h-5 w-5" /> {{ __('driver.running_late') }}
                </x-filament::button>
                <div class="rp-dv-late-pick" x-show="late" x-cloak>
                    <div class="rp-dv-label">{{ __('driver.late_how_much') }}</div>
                    <div class="rp-dv-late-grid">
                        @foreach (\App\Support\Drivers\DriverFlow::LATE_STEPS as $m)
                            <button type="button" class="rp-dv-pill" x-on:click="late = false; RPDriver.act({{ $job->id }}, 'late', { minutes: {{ $m }} }).then(() => $wire.$refresh())">{{ __('driver.minutes', ['n' => $m]) }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @foreach ([$this->declineAction, $this->damageAction] as $action)
                @if ($action->isVisible())
                    {{ $action }}
                @endif
            @endforeach
        </div>

        @if ($job->type === 'delivery' && $job->isOpen() && ! \App\Support\Drivers\DriverWork::canHandOver($job))
            <p class="rp-dv-meta">{{ __('driver.handover_after_confirm') }}</p>
        @endif
        @if ($job->type === 'charter' && $job->role === 'crew' && $job->isOpen())
            <p class="rp-dv-meta">{{ __('driver.captain_starts') }}</p>
        @endif
        @if ($job->type === 'collection' && $job->isOpen() && ! \App\Support\Drivers\DriverWork::canCollect($job))
            <p class="rp-dv-meta">{{ __('driver.collect_after_handover') }}</p>
        @endif

        <button type="button" wire:click="messageOffice" class="rp-dv-link" style="margin-bottom:.5rem"><x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-4 w-4" /> {{ __('driver.message_office') }}</button>
        <a href="{{ \App\Filament\Pages\MyJobs::getUrl() }}" class="rp-dv-back">{{ __('driver.all_jobs') }}</a>
    </div>
</x-filament-panels::page>
