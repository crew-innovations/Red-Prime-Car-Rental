@php
    $report = $this->report();
    $rows = $report->rows($by);
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $pct = fn ($v) => \App\Support\Pricing\Discounts::percentText((float) $v);
    $total = collect($rows)->sum('total');
    $list = collect($rows)->sum('list');
@endphp
<x-filament-panels::page>
    <div class="rp-rep-bar">
        <div class="rp-rep-range">
            <label>From <input type="date" wire:model.live="from"></label>
            <label>Until <input type="date" wire:model.live="until"></label>
        </div>
        <div class="rp-rep-quick">
            <button type="button" wire:click="setRange('month')">This month</button>
            <button type="button" wire:click="setRange('last_month')">Last month</button>
            <button type="button" wire:click="setRange('quarter')">This quarter</button>
            <button type="button" wire:click="setRange('year')">This year</button>
        </div>
    </div>

    <div class="rp-rep-cards">
        <div class="rp-rep-card rp-rep-main"><span>Total discount</span><strong>{{ $aed($total) }}</strong><em>Off the price list, before VAT</em></div>
        <div class="rp-rep-card"><span>With a discount</span><strong>{{ collect($rows)->sum('discounted') }}</strong><em>of {{ collect($rows)->sum('count') }} {{ strtolower(\App\Support\Reports\DiscountReport::SOURCES[$source]) }}</em></div>
        <div class="rp-rep-card"><span>Average discount</span><strong>{{ $list > 0 ? $pct(round($total / $list * 100, 2)) : '—' }}</strong><em>Of the price list of the discounted ones</em></div>
    </div>

    <x-filament::tabs label="Source">
        @foreach (\App\Support\Reports\DiscountReport::SOURCES as $key => $label)
            <x-filament::tabs.item :active="$source === $key" wire:click="$set('source', '{{ $key }}')">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
    <x-filament::tabs label="Group by">
        @foreach (\App\Support\Reports\DiscountReport::GROUPS as $key => $label)
            <x-filament::tabs.item :active="$by === $key" wire:click="$set('by', '{{ $key }}')">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div class="rp-rep-table-wrap">
        <table class="rp-rep-table">
            <thead>
                <tr>
                    <th>{{ \App\Support\Reports\DiscountReport::GROUPS[$by] }}</th>
                    <th class="r">{{ \App\Support\Reports\DiscountReport::SOURCES[$source] }}</th>
                    <th class="r">With a discount</th>
                    <th class="r">Total discount</th>
                    <th class="r">Average</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="r">{{ $row['count'] }}</td>
                        <td class="r">{{ $row['discounted'] }}</td>
                        <td class="r"><strong>{{ $aed($row['total']) }}</strong></td>
                        <td class="r">{{ $row['discounted'] ? $pct($row['average']) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="rp-muted">Nothing in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-filament::section heading="How these numbers are worked out" icon="heroicon-o-information-circle" collapsible collapsed compact>
        <ul class="rp-rep-defs">
            <li><strong>Discount:</strong> the price list minus the agreed price, before VAT: lower rental price, % or amount off, discounted or waived extras. A price above the list is not a discount.</li>
            <li><strong>Bookings:</strong> made in the period, not cancelled. <strong>Quotations sent:</strong> sent in the period.</li>
            <li><strong>Agent:</strong> the person who set the price (or the booking / quotation owner).</li>
            <li><strong>Average:</strong> total discount ÷ price list of the discounted ones, so a big discount on a small extra doesn't hide a small one on a big rental.</li>
            <li>Only prices set from 4 Nov 2026 carry the discount figure. Older records are counted but show no discount.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
