@php
    $pipeline = $this->getPipeline();
    $columns = $this->getColumns();
    $counts = $this->getCounts();
@endphp

<x-filament-panels::page>
    <div class="rp-board-bar">
        <x-filament::tabs>
            @foreach (\App\Enums\Pipeline::cases() as $p)
                <x-filament::tabs.item
                    :active="$pipeline === $p"
                    :icon="$p->getIcon()"
                    :badge="($counts[$p->value] ?? 0) ?: null"
                    wire:click="setPipeline('{{ $p->value }}')"
                >
                    {{ $p->getLabel() }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <label class="rp-board-mine">
            <x-filament::input.checkbox wire:model.live="mine" />
            <span>Only my leads</span>
        </label>
    </div>

    <div
        class="rp-board"
        x-data="{ dragging: null, over: null }"
        wire:loading.class="opacity-60"
        wire:target="moveLead,setPipeline,mine"
    >
        @foreach ($columns as $key => $column)
            <section
                @class(['rp-col', 'is-won' => $column['won']])
                x-bind:class="{ 'is-over': over === '{{ $key }}' }"
                x-on:dragover.prevent="over = '{{ $key }}'"
                x-on:dragleave.self="over = null"
                x-on:drop.prevent="over = null; if (dragging) { $wire.moveLead(dragging, '{{ $key }}') } dragging = null"
            >
                <header class="rp-col-head">
                    <div class="rp-col-title">
                        <span>{{ $column['label'] }}</span>
                        <span class="rp-col-count">{{ $column['leads']->count() }}</span>
                    </div>
                    @if ($column['budget'] > 0)
                        <div class="rp-col-sum">AED {{ number_format($column['budget']) }}</div>
                    @endif
                </header>

                <div class="rp-col-body">
                    @forelse ($column['leads'] as $lead)
                        <article
                            class="rp-card"
                            draggable="true"
                            wire:key="lead-{{ $lead->id }}"
                            x-on:dragstart="dragging = {{ $lead->id }}; $event.dataTransfer.effectAllowed = 'move'"
                            x-on:dragend="dragging = null; over = null"
                            x-bind:class="{ 'is-dragging': dragging === {{ $lead->id }} }"
                        >
                            <div class="rp-card-top">
                                <span @class(['rp-prio', 'is-'.$lead->priority?->value]) title="{{ $lead->priority?->getLabel() }}"></span>
                                <a href="{{ \App\Filament\Resources\LeadResource::getUrl('edit', ['record' => $lead]) }}" class="rp-card-name">{{ $lead->name }}</a>

                                <x-filament::dropdown placement="bottom-end" teleport>
                                    <x-slot name="trigger">
                                        <button type="button" class="rp-card-menu" title="Move to stage">
                                            <x-filament::icon icon="heroicon-m-ellipsis-horizontal" class="h-4 w-4" />
                                        </button>
                                    </x-slot>
                                    <x-filament::dropdown.list>
                                        @foreach ($pipeline->stageOptions() as $to => $toLabel)
                                            @continue($to === $lead->stage)
                                            <x-filament::dropdown.list.item
                                                wire:click="moveLead({{ $lead->id }}, '{{ $to }}')"
                                                :color="$to === \App\Enums\Pipeline::LOST ? 'danger' : 'gray'"
                                            >
                                                {{ $toLabel }}
                                            </x-filament::dropdown.list.item>
                                        @endforeach
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>
                            </div>

                            @if ($lead->company_name)
                                <div class="rp-card-line"><b>{{ $lead->company_name }}</b></div>
                            @endif
                            @if ($lead->interestedIn)
                                <div class="rp-card-line">{{ $lead->interestedIn->name }}</div>
                            @elseif ($lead->service_type)
                                <div class="rp-card-line">{{ $lead->service_type->getLabel() }}</div>
                            @endif

                            <div class="rp-card-meta">
                                @if ($lead->budget)
                                    <span class="rp-tag">AED {{ number_format((float) $lead->budget) }}</span>
                                @endif
                                @if ($lead->preferred_date)
                                    <span class="rp-tag">{{ $lead->preferred_date->format('d M') }}</span>
                                @endif
                                @if ($lead->leadSource)
                                    <span class="rp-tag is-muted">{{ $lead->leadSource->name }}</span>
                                @endif
                            </div>

                            <div class="rp-card-foot">
                                @if ($lead->next_follow_up_at)
                                    <span @class(['rp-follow', 'is-due' => $lead->next_follow_up_at->isPast()])>
                                        <x-filament::icon icon="heroicon-m-clock" class="h-3.5 w-3.5" />
                                        {{ $lead->next_follow_up_at->isPast() ? 'Due ' : '' }}{{ $lead->next_follow_up_at->format('d M, H:i') }}
                                    </span>
                                @else
                                    <span class="rp-follow">{{ $lead->updated_at->diffForHumans(short: true) }}</span>
                                @endif

                                <span class="rp-card-icons">
                                    @if ($url = \App\Support\Phone::whatsappUrl($lead->whatsapp ?: $lead->phone, "Hi {$lead->name}, this is RED PRIME RENTALS. "))
                                        <a href="{{ $url }}" target="_blank" title="WhatsApp" class="rp-icon-btn is-wa">
                                            <x-filament::icon icon="heroicon-m-chat-bubble-left-right" class="h-4 w-4" />
                                        </a>
                                    @endif
                                    @if ($lead->assignee)
                                        <span class="rp-initials" title="{{ $lead->assignee->name }}">{{ \Illuminate\Support\Str::of($lead->assignee->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                                    @endif
                                </span>
                            </div>
                        </article>
                    @empty
                        <div class="rp-col-empty">Drop a lead here</div>
                    @endforelse
                </div>
            </section>
        @endforeach

        <section
            class="rp-col is-lost"
            x-bind:class="{ 'is-over': over === 'lost' }"
            x-on:dragover.prevent="over = 'lost'"
            x-on:dragleave.self="over = null"
            x-on:drop.prevent="over = null; if (dragging) { $wire.moveLead(dragging, 'lost') } dragging = null"
        >
            <header class="rp-col-head">
                <div class="rp-col-title"><span>Lost</span></div>
            </header>
            <div class="rp-col-body">
                <div class="rp-col-empty">
                    <x-filament::icon icon="heroicon-o-x-circle" class="mx-auto mb-1 h-6 w-6" />
                    Drop here to mark as lost
                </div>
            </div>
        </section>
    </div>

    <p class="rp-board-hint">
        Drag a card to move it to another stage, or use the ••• menu. Won stages show deals from the last {{ 30 }} days.
    </p>
</x-filament-panels::page>
