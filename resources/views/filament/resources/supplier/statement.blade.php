@php($aed = fn ($v) => number_format((float) $v, 2))
<div class="rp-rep-table-wrap">
    <table class="rp-rep-table">
        <thead><tr><th>Date</th><th>Ref</th><th>What</th><th class="r">Bill</th><th class="r">Paid</th><th class="r">Balance</th></tr></thead>
        <tbody>
            <tr><td colspan="5">Opening balance{{ $s->opening_balance_date ? ' ('.$s->opening_balance_date->format('d M Y').')' : '' }}</td><td class="r">{{ $aed($st['opening']) }}</td></tr>
            @forelse ($st['lines'] as $l)
                <tr><td>{{ $l['date']->format('d M Y') }}</td><td>{{ $l['ref'] }}</td><td>{{ $l['text'] }}</td><td class="r">{{ $l['debit'] ? $aed($l['debit']) : '' }}</td><td class="r">{{ $l['credit'] ? $aed($l['credit']) : '' }}</td><td class="r">{{ $aed($l['balance']) }}</td></tr>
            @empty
                <tr><td colspan="6" class="rp-muted">No bills or payments yet.</td></tr>
            @endforelse
        </tbody>
        <tfoot><tr><td colspan="5">We owe now (AED)</td><td class="r"><strong>{{ $aed($st['closing']) }}</strong></td></tr></tfoot>
    </table>
</div>
<p class="rp-muted" style="font-size:.8rem;margin-top:.5rem">Bills waiting for approval and reversed rows are not included.</p>
