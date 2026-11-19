@php
    $r = $this->report();
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $bookingUrl = fn ($b) => \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $b]);
    $emirates = \App\Filament\Pages\MoneySettings::EMIRATES;
@endphp
<x-filament-panels::page>
    <div class="rp-rep-bar">
        <div class="rp-rep-range">
            <label>From <input type="date" wire:model.live="from"></label>
            <label>Until <input type="date" wire:model.live="until"></label>
        </div>
        <div class="rp-rep-quick">
            <button type="button" wire:click="setRange('today')">Today</button>
            <button type="button" wire:click="setRange('week')">This week</button>
            <button type="button" wire:click="setRange('month')">This month</button>
            <button type="button" wire:click="setRange('last_month')">Last month</button>
            <button type="button" wire:click="setRange('quarter')">This quarter</button>
        </div>
    </div>

    <x-filament::tabs>
        @foreach (self::VIEWS as $key => $label)
            <x-filament::tabs.item :active="$tab === $key" wire:click="$set('tab', '{{ $key }}')">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div class="rp-rep-table-wrap">
        @if ($tab === 'moves')
            @php($rows = $r->movements())
            <table class="rp-rep-table">
                <thead><tr><th>When</th><th></th><th>Booking / contract</th><th>Customer</th><th>Car</th><th class="r">Km</th><th>Fuel</th><th>By</th></tr></thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row['at']->format('D d M, H:i') }}</td>
                        <td><span style="font-weight:700;color:rgb(var(--{{ $row['kind'] === 'Out' ? 'warning' : 'success' }}-600))">{{ $row['kind'] }}</span></td>
                        <td><a href="{{ $bookingUrl($row['b']) }}">{{ $row['b']->reference }}</a>{{ $row['b']->contract ? ' · '.$row['b']->contract->number : '' }}</td>
                        <td>{{ $row['b']->customer?->name }}</td>
                        <td>{{ $row['b']->bookable?->name }}{{ $row['b']->bookable?->registration_number ? ' · '.$row['b']->bookable->registration_number : '' }}</td>
                        <td class="r">{{ $row['km'] !== null ? number_format($row['km']) : '—' }}</td>
                        <td>{{ $row['fuel'] }}</td>
                        <td>{{ $row['by'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="opacity:.7">No cars went out or came back in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        @elseif ($tab === 'use')
            @php($rows = $r->carUse())
            <table class="rp-rep-table">
                <thead><tr><th>Car</th><th class="r">Bookings</th><th class="r">Days rented</th><th class="r">Use</th><th class="r">Revenue (no VAT)</th></tr></thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['car']->name }}{{ $row['car']->registration_number ? ' · '.$row['car']->registration_number : '' }}</td>
                        <td class="r">{{ $row['bookings'] }}</td>
                        <td class="r">{{ $row['days'] }}</td>
                        <td class="r"><span style="display:inline-block;width:60px;height:6px;border-radius:4px;background:rgba(127,127,127,.2);vertical-align:middle;margin-right:6px"><span style="display:block;height:6px;border-radius:4px;width:{{ $row['percent'] }}%;background:rgb(var(--primary-500))"></span></span>{{ $row['percent'] }}%</td>
                        <td class="r">{{ $aed($row['revenue']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr><td>Total</td><td class="r">{{ collect($rows)->sum('bookings') }}</td><td class="r">{{ collect($rows)->sum('days') }}</td><td></td><td class="r">{{ $aed(collect($rows)->sum('revenue')) }}</td></tr></tfoot>
            </table>
        @elseif ($tab === 'unpaid')
            @php($rows = $r->unpaid())
            <table class="rp-rep-table">
                <thead><tr><th>Booking / contract</th><th>Customer</th><th>Car</th><th>Returns / returned</th><th class="r">Total</th><th class="r">Paid</th><th class="r">Owed</th></tr></thead>
                <tbody>
                @forelse ($rows as $b)
                    <tr>
                        <td><a href="{{ $bookingUrl($b) }}">{{ $b->reference }}</a>{{ $b->contract ? ' · '.$b->contract->number : '' }}</td>
                        <td>{{ $b->customer?->name }}<br><span style="opacity:.7">{{ $b->customer?->phone }}</span></td>
                        <td>{{ $b->bookable?->name }}</td>
                        <td>{{ ($b->returned_at ?? $b->end_at)->format('d M Y') }}{{ $b->returned_at ? '' : ' (out)' }}</td>
                        <td class="r">{{ $aed($b->amountDue()) }}</td>
                        <td class="r">{{ $aed($b->paid_amount) }}</td>
                        <td class="r" style="color:rgb(var(--danger-600));font-weight:700">{{ $aed($b->balance) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="opacity:.7">Nothing owed on car rentals.</td></tr>
                @endforelse
                </tbody>
                @if ($rows)<tfoot><tr><td colspan="6">Total owed</td><td class="r">{{ $aed(collect($rows)->sum('balance')) }}</td></tr></tfoot>@endif
            </table>
        @elseif ($tab === 'vat')
            @php($rows = $r->vatByEmirate())
            <table class="rp-rep-table">
                <thead><tr><th>Emirate</th><th class="r">Invoices</th><th class="r">Sales without VAT</th><th class="r">VAT</th></tr></thead>
                <tbody>
                @forelse ($rows as $em => $row)
                    <tr><td>{{ $emirates[$em] ?? $em }}</td><td class="r">{{ $row['count'] }}</td><td class="r">{{ $aed($row['net']) }}</td><td class="r">{{ $aed($row['vat']) }}</td></tr>
                @empty
                    <tr><td colspan="4" style="opacity:.7">No invoices issued in this period.</td></tr>
                @endforelse
                </tbody>
                @if ($rows)<tfoot><tr><td>Total</td><td class="r">{{ collect($rows)->sum('count') }}</td><td class="r">{{ $aed(collect($rows)->sum('net')) }}</td><td class="r">{{ $aed(collect($rows)->sum('vat')) }}</td></tr></tfoot>@endif
            </table>
            <p style="font-size:.8rem;opacity:.7;padding:.75rem 1rem">From invoices and credit notes issued in the period. The emirate is the booking's (Rental agreement section), else the default in Settings › Bank, cash and marketplaces. Check with your accountant before filing.</p>
        @elseif ($tab === 'fines')
            {{-- 19 Nov: fines and tolls per car / per customer, by the date of the offence or the pass. --}}
            @php($rows = \App\Support\Fines\RentalCharges::report($by === 'customer' ? 'customer' : 'car', $r->from, $r->until))
            <div style="padding:.75rem 1rem">
                <x-filament::tabs>
                    <x-filament::tabs.item :active="$by !== 'customer'" wire:click="$set('by', 'car')">Per car</x-filament::tabs.item>
                    <x-filament::tabs.item :active="$by === 'customer'" wire:click="$set('by', 'customer')">Per customer</x-filament::tabs.item>
                </x-filament::tabs>
            </div>
            <table class="rp-rep-table">
                <thead><tr><th>{{ $by === 'customer' ? 'Customer' : 'Car' }}</th><th class="r">Fines</th><th class="r">Amount (with fees)</th><th class="r">Passed to customer</th><th class="r">Collected</th><th class="r">Still to sort</th><th class="r">Company cost</th><th class="r">Not paid to authority</th><th class="r">Tolls</th><th class="r">Toll amount</th><th class="r">Tolls charged</th><th class="r">Tolls to sort</th></tr></thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>@if ($row['url']) <a href="{{ $row['url'] }}">{{ $row['name'] }}</a> @else {{ $row['name'] }} @endif</td>
                        <td class="r">{{ $row['fines'] }}</td>
                        <td class="r">{{ $aed($row['fines_amount']) }}</td>
                        <td class="r">{{ $aed($row['fines_passed_on']) }}</td>
                        <td class="r">{{ $aed($row['fines_collected']) }}</td>
                        <td class="r">{{ $aed($row['fines_open']) }}</td>
                        <td class="r">{{ $aed($row['fines_company']) }}</td>
                        <td class="r">{{ $aed($row['fines_unpaid_authority']) }}</td>
                        <td class="r">{{ $row['tolls'] }}</td>
                        <td class="r">{{ $aed($row['tolls_amount']) }}</td>
                        <td class="r">{{ $aed($row['tolls_charged']) }}</td>
                        <td class="r">{{ $aed($row['tolls_open']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="12" style="opacity:.7">No fines or tolls in this period.</td></tr>
                @endforelse
                </tbody>
                @if ($rows)<tfoot><tr><td>Total</td>
                    @foreach (['fines', 'fines_amount', 'fines_passed_on', 'fines_collected', 'fines_open', 'fines_company', 'fines_unpaid_authority', 'tolls', 'tolls_amount', 'tolls_charged', 'tolls_open'] as $k)
                        <td class="r">{{ in_array($k, ['fines', 'tolls'], true) ? collect($rows)->sum($k) : $aed(collect($rows)->sum($k)) }}</td>
                    @endforeach
                </tr></tfoot>@endif
            </table>
        @else
            @php($rows = $r->cash())
            <table class="rp-rep-table">
                <thead><tr><th>Account</th><th class="r">Payments</th><th class="r">Money in</th><th class="r">Money out</th><th class="r">Net</th></tr></thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="r">{{ $row['count'] }}</td><td class="r">{{ $aed($row['in']) }}</td><td class="r">{{ $aed($row['out']) }}</td><td class="r" style="font-weight:700">{{ $aed($row['net']) }}</td></tr>
                @empty
                    <tr><td colspan="5" style="opacity:.7">No money moved in this period.</td></tr>
                @endforelse
                </tbody>
                @if ($rows)<tfoot><tr><td>Total</td><td class="r">{{ collect($rows)->sum('count') }}</td><td class="r">{{ $aed(collect($rows)->sum('in')) }}</td><td class="r">{{ $aed(collect($rows)->sum('out')) }}</td><td class="r">{{ $aed(collect($rows)->sum('net')) }}</td></tr></tfoot>@endif
            </table>
            @php($cheques = $r->cheques())
            @if ($cheques)
                <table class="rp-rep-table" style="margin-top:1rem">
                    <thead><tr><th>Cheque</th><th>Cheque date</th><th>From</th><th>Booking</th><th class="r">Amount</th></tr></thead>
                    <tbody>
                    @foreach ($cheques as $t)
                        <tr><td>{{ $t->cheque_number ?: '—' }}</td><td>{{ $t->cheque_date?->format('d M Y') ?? '—' }}</td><td>{{ $t->customer?->name ?? $t->booking?->customer?->name }}</td>
                            <td>@if ($t->booking)<a href="{{ $bookingUrl($t->booking) }}">{{ $t->booking->reference }}</a>@endif</td><td class="r">{{ $aed($t->amount) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if (! \App\Support\Accounts\MoneyAccounts::options())
                <p style="font-size:.8rem;opacity:.7;padding:.75rem 1rem">Add your bank and cash accounts in Settings › Bank, cash and marketplaces, then pick one when recording a payment.</p>
            @endif
        @endif
    </div>
</x-filament-panels::page>
