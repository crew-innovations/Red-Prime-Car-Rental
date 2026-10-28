@php
    $report = $this->report();
    $s = $report->summary();
    $rows = $report->rows($by);
    $aed = fn ($v) => $v === null ? '—' : 'AED '.number_format((float) $v, 2);
    $labels = \App\Models\Transaction::categories();
    $last = $by === 'month' ? 'Net' : ($by === 'category' ? 'Effect' : 'Contribution');
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
        <div class="rp-rep-card rp-rep-main"><span>Earned revenue</span><strong>{{ $aed($s['earned']) }}</strong><em>{{ $s['earned_count'] }} completed in the period · without VAT · incl. {{ $aed($s['extra_charges']) }} return charges</em></div>
        <div class="rp-rep-card"><span>Cash received</span><strong>{{ $aed($s['received']) }}</strong><em>Payments in the period, deposits not included</em></div>
        <div class="rp-rep-card"><span>Contribution</span><strong class="{{ $s['contribution'] < 0 ? 'rp-neg' : '' }}">{{ $aed($s['contribution']) }}</strong><em>Earned revenue − {{ $aed($s['direct_costs']) }} costs linked to those bookings</em></div>
        <div class="rp-rep-card"><span>Net result</span><strong class="{{ $s['net'] < 0 ? 'rp-neg' : '' }}">{{ $aed($s['net']) }}</strong><em>Earned revenue − {{ $aed($s['expenses']) }} all actual costs dated in the period. Complete only if every cost is entered.</em></div>
    </div>

    <div class="rp-rep-grid">
        <x-filament::section heading="Sales" icon="heroicon-o-document-text" compact>
            <dl class="rp-breakdown">
                <div><dt>Quoted<span>Quotations sent in the period</span></dt><dd>{{ $aed($s['quoted']) }}</dd></div>
                <div><dt>Booked<span>Confirmed bookings starting in the period</span></dt><dd>{{ $aed($s['confirmed']) }}</dd></div>
                <div><dt>Invoiced<span>Invoices issued minus credit notes</span></dt><dd>{{ $aed($s['invoiced']) }}</dd></div>
            </dl>
        </x-filament::section>
        <x-filament::section heading="Money held and owed" icon="heroicon-o-banknotes" compact>
            <dl class="rp-breakdown">
                <div><dt>To collect<span>All open balances today</span></dt><dd>{{ $aed($s['outstanding']) }}</dd></div>
                <div><dt>Security deposits held<span>Refundable, not revenue</span></dt><dd>{{ $aed($s['deposits_held']) }}</dd></div>
                <div><dt>Deposits returned<span>In the period, not a cost</span></dt><dd>{{ $aed($s['deposit_refunds']) }}</dd></div>
                <div><dt>Refunded payments<span>In the period</span></dt><dd>{{ $aed($s['refunds']) }}</dd></div>
            </dl>
        </x-filament::section>
        <x-filament::section heading="Costs" icon="heroicon-o-arrow-up-right" compact>
            <dl class="rp-breakdown">
                @forelse ($s['expenses_by'] as $cat => $total)
                    <div><dt>{{ $labels[$cat] ?? $cat }}</dt><dd>{{ $aed($total) }}</dd></div>
                @empty
                    <div><dt class="rp-muted">No costs in the period</dt></div>
                @endforelse
                <div class="rp-breakdown-note"><dt>Estimates (not counted)</dt><dd>{{ $aed($s['estimates']) }}</dd></div>
                <div class="rp-breakdown-note"><dt>Waiting for approval (not counted)</dt><dd>{{ $aed($s['pending_approval']) }}</dd></div>
                <div class="rp-breakdown-note"><dt>Reversed rows in the period</dt><dd>{{ $s['reversed_rows'] }}</dd></div>
            </dl>
        </x-filament::section>
    </div>

    <x-filament::tabs label="Group by">
        @foreach (\App\Filament\Pages\ProfitReports::VIEWS as $key => $label)
            <x-filament::tabs.item :active="$by === $key" wire:click="$set('by', '{{ $key }}')">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div class="rp-rep-table-wrap">
        <table class="rp-rep-table">
            <thead>
                <tr>
                    <th>{{ \App\Filament\Pages\ProfitReports::VIEWS[$by] }}</th>
                    @if (! in_array($by, ['category'], true))<th class="r">Bookings</th>@endif
                    <th class="r">Earned</th>
                    <th class="r">{{ $by === 'month' ? 'Cash received' : ($by === 'category' ? 'Received' : 'Paid by customer') }}</th>
                    <th class="r">Costs</th>
                    <th class="r">{{ $last }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>@if ($row['url'])<a href="{{ $row['url'] }}">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif</td>
                        @if (! in_array($by, ['category'], true))<td class="r">{{ $row['count'] }}</td>@endif
                        <td class="r">{{ $aed($row['earned']) }}</td>
                        <td class="r">{{ $aed($row['received']) }}</td>
                        <td class="r">{{ $aed($row['costs']) }}</td>
                        <td class="r {{ $row['contribution'] < 0 ? 'rp-neg' : '' }}"><strong>{{ $aed($row['contribution']) }}</strong></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="rp-muted">Nothing in this period.</td></tr>
                @endforelse
            </tbody>
            @if ($rows)
                <tfoot>
                    <tr>
                        <td>Total</td>
                        @if (! in_array($by, ['category'], true))<td class="r">{{ collect($rows)->sum('count') }}</td>@endif
                        <td class="r">{{ $by === 'category' ? '—' : $aed(collect($rows)->sum('earned')) }}</td>
                        <td class="r">{{ $aed(collect($rows)->sum('received')) }}</td>
                        <td class="r">{{ $aed(collect($rows)->sum('costs')) }}</td>
                        <td class="r"><strong>{{ $aed(collect($rows)->sum('contribution')) }}</strong></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <x-filament::section heading="How these numbers are worked out" icon="heroicon-o-information-circle" collapsible collapsed compact>
        <ul class="rp-rep-defs">
            <li><strong>Earned revenue:</strong> the price of rentals, charters and trips completed in the period (by return date), without VAT, plus return charges. Money kept on cancelled or no-show bookings counts when the booking ends.</li>
            <li><strong>Cash received:</strong> payments dated in the period. Security deposits are never revenue; deposit refunds are never costs.</li>
            <li><strong>Costs:</strong> actual, approved expenses. Estimates, expenses waiting for approval and reversed rows never count.</li>
            <li><strong>Contribution:</strong> earned revenue minus the costs linked to those bookings (and, for a car or yacht, its own costs dated in the period).</li>
            <li><strong>Net result:</strong> earned revenue minus every actual cost dated in the period. It is only a full net profit if all costs (rent, salaries…) are entered as expenses.</li>
            <li>Every row links to its booking, car or yacht; the money rows are under Finance › Transactions with the same dates.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
