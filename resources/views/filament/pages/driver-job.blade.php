@php
    $job = $this->record;
    $b = $this->booking();
    $car = $b?->bookable;
    $phone = $this->customerPhone();
    $wa = $this->whatsappUrl();
    $map = $this->mapUrl();
    $steps = $this->nextSteps();
    $check = $job->inspection ?? [];
@endphp

<x-filament-panels::page>
    <div class="rp-driver">
        <div class="rp-dv-hero">
            <div class="rp-dv-card-top">
                <span class="rp-dv-badge rp-dv-{{ $job->type }}">{{ $job->typeLabel() }}</span>
                <span class="rp-dv-badge rp-dv-s-{{ $job->statusColor() }}">{{ $job->statusLabel() }}</span>
            </div>
            <div class="rp-dv-when">{{ $job->scheduled_at->format('D d M') }} · <strong>{{ $job->scheduled_at->format('H:i') }}</strong>@if ($job->ends_at) – <strong>{{ $job->ends_at->format('H:i') }}</strong>@endif</div>
            <div class="rp-dv-car">{{ $car?->name ?? match ($job->type) { 'charter' => 'Yacht', 'transfer' => ($b?->vehicleCategory?->name ?? 'Car to be assigned'), default => 'Car' } }}@if ($car?->registration_number) <span class="rp-dv-plate">{{ $car->registration_number }}</span>@endif</div>
            @if ($job->type === 'charter')
                <div class="rp-dv-meta">{{ $b?->guests ? $b->guests.' guests' : 'Guests not set' }}@if ($car?->max_guests) · max {{ $car->max_guests }}@endif @if ($b?->captain) · captain {{ $b->captain->name }}@endif @if ($b?->crew_ids) · crew {{ $b->crewMembers()->pluck('name')->implode(', ') }}@endif</div>
            @endif
        </div>

        @if ($job->type === 'transfer' && $b)
            <div class="rp-dv-section">
                <div class="rp-dv-label">{{ $b->transferService?->name ?? 'Transfer' }}</div>
                <div class="rp-dv-meta">{{ collect([$b->guests ? $b->guests.' passengers' : null, $b->luggage ? $b->luggage.' bags' : null, $b->child_seats ? $b->child_seats.' child seat(s)' : null])->filter()->implode(' · ') ?: 'Passengers not set' }}</div>
                @if ($b->flight_number)
                    <div class="rp-dv-value">Flight {{ $b->flight_number }}{{ $b->airline ? ' · '.$b->airline : '' }}{{ $b->terminal ? ' · Terminal '.$b->terminal : '' }}</div>
                @endif
                @if ($b->special_requests)<div class="rp-dv-meta" style="white-space:pre-line">{{ $b->special_requests }}</div>@endif
            </div>
            <div class="rp-dv-section">
                <div class="rp-dv-label">Pick up</div>
                <div class="rp-dv-value">{{ $b->pickup_location ?: 'Not set yet: call the office' }}</div>
                @foreach ((array) $b->stops as $n => $stop)
                    @if (! empty($stop['address']))<div class="rp-dv-meta">Stop {{ $n + 1 }}: {{ $stop['address'] }}</div>@endif
                @endforeach
                <div class="rp-dv-label" style="margin-top:.6rem">Drop off</div>
                <div class="rp-dv-value">{{ $b->dropoff_location ?: ($b->journey_type === 'hourly' ? 'As the customer asks (by the hour)' : 'Not set yet') }}</div>
                @if ($b->route_km)<div class="rp-dv-meta">About {{ rtrim(rtrim(number_format((float) $b->route_km, 1), '0'), '.') }} km · {{ $b->route_minutes }} min</div>@endif
                @if ($b->return_at)<div class="rp-dv-meta">Return trip: {{ $b->return_at->format('D d M H:i') }}</div>@endif
                @if ($map)
                    <a class="rp-dv-link" href="{{ $map }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-m-map" class="h-4 w-4" /> Open the route in maps</a>
                @endif
            </div>
        @else
        <div class="rp-dv-section">
            <div class="rp-dv-label">{{ match ($job->type) { 'delivery' => 'Deliver to', 'charter' => 'Marina / meeting point', default => 'Collect from' } }}</div>
            <div class="rp-dv-value">{{ $job->address ?: 'Address not set yet: call the office' }}</div>
            @if ($map)
                <a class="rp-dv-link" href="{{ $map }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-m-map" class="h-4 w-4" /> Open in maps</a>
            @endif
        </div>
        @endif

        <div class="rp-dv-section">
            <div class="rp-dv-label">Customer</div>
            <div class="rp-dv-value">{{ $b?->customer?->name ?? '-' }}</div>
            @if ($phone)
                <div class="rp-dv-actions-row">
                    <a class="rp-dv-pill" href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}"><x-filament::icon icon="heroicon-m-phone" class="h-4 w-4" /> Call</a>
                    @if ($wa)
                        <a class="rp-dv-pill" href="{{ $wa }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-4 w-4" /> WhatsApp</a>
                    @endif
                </div>
            @endif
            <div class="rp-dv-meta">Booking {{ $b?->reference }} · {{ $b?->start_at?->format('d M H:i') }} → {{ $b?->end_at?->format('d M H:i') }}</div>
        </div>

        @if ($this->showInstructions() && filled($b?->driver_instructions))
            <div class="rp-dv-section rp-dv-note">
                <div class="rp-dv-label">Instructions</div>
                <div class="rp-dv-value" style="white-space:pre-line">{{ $b->driver_instructions }}</div>
            </div>
        @endif

        @if ($job->status === 'waiting')
            <div class="rp-dv-section rp-dv-note">
                <div class="rp-dv-label">Waiting for the office</div>
                <div class="rp-dv-value">Your check is sent. Do not give the keys until you get the OK here.</div>
            </div>
        @elseif ($job->type === 'delivery' && ! empty($check['rejected']) && $job->isOpen())
            <div class="rp-dv-section rp-dv-note">
                <div class="rp-dv-label">The office said</div>
                <div class="rp-dv-value">{{ $check['rejected'] }}</div>
            </div>
        @endif

        @if ($job->type === 'collection' && $b && ($handover = $b->getMedia('delivery')->sortByDesc('id')->take(8))->isNotEmpty())
            <div class="rp-dv-section">
                <div class="rp-dv-label">Photos from the handover</div>
                <div class="rp-dv-photos">
                    @foreach ($handover as $m)
                        <a href="{{ $m->getTemporaryUrl(now()->addMinutes(30)) }}" target="_blank" rel="noopener"><img src="{{ $m->getTemporaryUrl(now()->addMinutes(30)) }}" alt="Handover photo" loading="lazy"></a>
                    @endforeach
                </div>
                @if ($b->delivery_km || $b->delivery_fuel)
                    <div class="rp-dv-meta">At handover: {{ $b->delivery_km ? number_format($b->delivery_km).' km' : '' }}{{ $b->delivery_fuel ? ' · fuel '.(\App\Models\Booking::FUEL_LEVELS[$b->delivery_fuel] ?? $b->delivery_fuel) : '' }}</div>
                @endif
            </div>
        @endif

        @if ($job->isOpen())
            @php($list = in_array($job->type, ['charter', 'transfer'], true) ? [] : (array) \App\Models\Setting::read($job->type === 'delivery' ? 'handover_checklist' : 'return_checklist', []))
            @if ($list)
                <div class="rp-dv-section">
                    <div class="rp-dv-label">You will check</div>
                    <ul class="rp-dv-checklist">
                        @foreach ($list as $item)<li>{{ $item }}</li>@endforeach
                    </ul>
                </div>
            @endif
        @endif

        @if ($job->status === 'done' && $check)
            <div class="rp-dv-section">
                <div class="rp-dv-label">Recorded {{ $job->done_at?->format('d M H:i') }}</div>
                <div class="rp-dv-value">
                    @if (isset($check['km']))Odometer {{ number_format((int) $check['km']) }} km · @endif
                    @if (! empty($check['fuel']))Fuel {{ \App\Models\Booking::FUEL_LEVELS[$check['fuel']] ?? $check['fuel'] }} · @endif
                    @if (! empty($check['condition'])){{ \App\Models\Booking::CONDITIONS[$check['condition']] ?? $check['condition'] }} · @endif
                    {{ (int) ($check['photos'] ?? 0) }} photo(s)
                </div>
                @if (! empty($check['notes']))<div class="rp-dv-meta">{{ $check['notes'] }}</div>@endif
            </div>
        @endif

        <div class="rp-dv-buttons">
            @foreach ($steps as $step)
                <x-filament::button size="xl" color="gray" class="rp-dv-bigbtn" wire:click="setStep('{{ $step }}')" wire:loading.attr="disabled" wire:target="setStep">
                    {{ \App\Models\BookingJob::DRIVER_STEPS[$step] }}
                </x-filament::button>
            @endforeach
            @foreach ([$this->handoverAction, $this->collectAction, $this->startCharterAction, $this->finishCharterAction, $this->damageAction] as $action)
                @if ($action->isVisible())
                    {{ $action }}
                @endif
            @endforeach
        </div>

        @if ($job->type === 'delivery' && $job->isOpen() && ! \App\Support\Drivers\DriverWork::canHandOver($job))
            <p class="rp-dv-meta">The handover button appears once the office has confirmed the booking.</p>
        @endif
        @if ($job->type === 'charter' && $job->role === 'crew' && $job->isOpen())
            <p class="rp-dv-meta">The captain starts and finishes the charter.</p>
        @endif
        @if ($job->type === 'collection' && $job->isOpen() && ! \App\Support\Drivers\DriverWork::canCollect($job))
            <p class="rp-dv-meta">The collection button appears once the car has been handed over.</p>
        @endif

        <button type="button" wire:click="messageOffice" class="rp-dv-link" style="margin-bottom:.5rem"><x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-4 w-4" /> Message the office about this job</button>
        <a href="{{ \App\Filament\Pages\MyJobs::getUrl() }}" class="rp-dv-back">← All my jobs</a>
    </div>
</x-filament-panels::page>
