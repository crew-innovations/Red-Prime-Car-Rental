<x-filament-panels::page>
    <div class="rp-pc-grid">
        <div>
            <x-filament::section>
                {{ $this->form }}
            </x-filament::section>
        </div>

        <div>
            @php($r = $this->result())
            <x-filament::section icon="heroicon-o-banknotes" heading="Price">
                @if (! $r)
                    <p class="rp-muted">Pick what to rent and the dates. If nothing shows, there is no rate on the price list for it.</p>
                @else
                    <dl class="rp-breakdown">
                        <div>
                            <dt>{{ $r['item']['name'] }}<span>{{ $r['base_label'] }}</span></dt>
                            <dd>AED {{ number_format($r['base_amount'], 2) }}</dd>
                        </div>
                        @if ($r['rule'])
                            <div class="rp-breakdown-note"><dt>{{ $r['rule']['name'] }} · {{ $r['rule']['effect'] }}@if ($r['standard_rate'] !== null && abs($r['standard_rate'] - $r['rate']) > 0.005)<span>Standard rate AED {{ number_format($r['standard_rate'], 2) }}</span>@endif</dt></div>
                        @endif
                        @foreach ($r['lines'] as $line)
                            <div>
                                <dt>{{ $line['label'] }}@if ((float) $line['qty'] != 1)<span>{{ \App\Support\Pricing\PriceEngine::qtyText((float) $line['qty']) }} × AED {{ number_format($line['unit_price'], 2) }}</span>@endif</dt>
                                <dd>AED {{ number_format($line['amount'], 2) }}</dd>
                            </div>
                        @endforeach
                        @if ($r['totals']['tax'] > 0)
                            <div><dt>VAT</dt><dd>AED {{ number_format($r['totals']['tax'], 2) }}</dd></div>
                        @endif
                        <div class="rp-breakdown-total"><dt>Total</dt><dd>AED {{ number_format($r['totals']['total'], 2) }}</dd></div>
                        @if ($r['deposit'] > 0)
                            <div class="rp-breakdown-note"><dt>Security deposit (refundable, not part of the price)</dt><dd>AED {{ number_format($r['deposit'], 2) }}</dd></div>
                        @endif
                    </dl>
                    @foreach (array_merge($r['notes'], $r['warnings']) as $note)
                        <p class="rp-warn">{{ $note }}</p>
                    @endforeach
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
