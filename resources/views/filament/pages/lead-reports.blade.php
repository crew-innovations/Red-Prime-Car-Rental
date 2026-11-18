@php
    $report = $this->report();
    $s = $report->summary();
    $rows = $report->rows($by);
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
@endphp
<x-filament-panels::page>
    <div class="rp-rep-bar">
        <div class="rp-rep-range">
            <label>From <input type="date" wire:model.live="from"></label>
            <label>Until <input type="date" wire:model.live="until"></label>
            <label>Service
                <select wire:model.live="service">
                    <option value="">All services</option>
                    @foreach (\App\Enums\ServiceType::cases() as $st)<option value="{{ $st->value }}">{{ $st->getLabel() }}</option>@endforeach
                </select>
            </label>
        </div>
        <div class="rp-rep-quick">
            <button type="button" wire:click="setRange('month')">This month</button>
            <button type="button" wire:click="setRange('last_month')">Last month</button>
            <button type="button" wire:click="setRange('quarter')">This quarter</button>
            <button type="button" wire:click="setRange('year')">This year</button>
        </div>
    </div>

    <div class="rp-rep-cards">
        <div class="rp-rep-card rp-rep-main"><span>Leads received</span><strong>{{ $s['leads'] }}</strong><em>{{ $s['unassigned'] }} not given to anyone yet</em></div>
        <div class="rp-rep-card"><span>Won</span><strong>{{ $s['won'] }}</strong><em>Became a customer</em></div>
        <div class="rp-rep-card"><span>Lost</span><strong>{{ $s['lost'] }}</strong><em>Closed with a reason</em></div>
        <div class="rp-rep-card"><span>Conversion</span><strong>{{ $pct($s['conversion']) }}</strong><em>Won ÷ leads received in the period ({{ $s['open'] }} still open)</em></div>
    </div>

    <x-filament::tabs label="Group by">
        @foreach (\App\Support\Reports\LeadReport::VIEWS as $key => $label)
            <x-filament::tabs.item :active="$by === $key" wire:click="$set('by', '{{ $key }}')">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div class="rp-rep-table-wrap">
        <table class="rp-rep-table">
            <thead>
                <tr><th>{{ \App\Support\Reports\LeadReport::VIEWS[$by] }}</th><th class="r">Leads</th><th class="r">Won</th><th class="r">Lost</th><th class="r">Open</th><th class="r">Conversion</th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="r">{{ $row['leads'] }}</td><td class="r">{{ $row['won'] }}</td><td class="r">{{ $row['lost'] }}</td><td class="r">{{ $row['open'] }}</td><td class="r"><strong>{{ $pct($row['conversion']) }}</strong></td></tr>
                @empty
                    <tr><td colspan="6" class="rp-muted">No leads in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="rp-muted" style="font-size:.85rem">Counts leads by the day they arrived. A lead won later still counts in the month it arrived. Agents see only their own leads.</p>
</x-filament-panels::page>
