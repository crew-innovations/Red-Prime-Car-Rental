@extends('pdf.layout')
@php
    /** 19 Nov (suggestion 3): deposit settlement / refund statement. $s = DepositSettlement, $b = Booking. */
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $contract = $b->contract;
@endphp
@section('title', 'Deposit statement '.$b->reference)
@section('doc-title', 'Deposit Statement')
@section('meta')
    <tr><td class="k">Booking</td><td class="r"><strong>{{ $b->reference }}</strong></td></tr>
    @if ($contract)<tr><td class="k">Contract</td><td class="r">{{ $contract->number }}</td></tr>@endif
    <tr><td class="k">Date</td><td class="r">{{ $s->created_at->format('d M Y') }}</td></tr>
@endsection
@section('content')
    <table class="parties" style="margin-top:14px">
        <tr>
            <td style="padding-right:8px"><div class="box"><div class="label">Customer</div><div class="strong">{{ \App\Support\Documents\PdfText::x($b->customer?->name) }}</div><div class="muted">{{ collect([$b->customer?->phone, $b->customer?->email])->filter()->implode(' · ') }}</div></div></td>
            <td style="padding-left:8px"><div class="box"><div class="label">Rental</div><div class="strong">{{ $b->bookable?->name }}</div><div class="muted">{{ $b->start_at?->format('d M Y') }} – {{ ($b->returned_at ?? $b->end_at)?->format('d M Y') }}</div></div></td>
        </tr>
    </table>

    <table class="lines">
        <thead><tr><th>What the rental cost</th><th class="r" style="width:24%">Amount</th></tr></thead>
        <tbody>
        @foreach ((array) $s->lines as $l)
            <tr><td>{{ \App\Support\Documents\PdfText::x($l['label']) }}</td><td class="r">{{ $aed($l['amount']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td style="width:60%"></td><td class="muted">Still owed before the deposit</td><td class="r">{{ $aed($s->owed) }}</td></tr>
        <tr><td></td><td class="muted">Security deposit held</td><td class="r">{{ $aed($s->held) }}</td></tr>
        <tr><td></td><td class="muted">Taken from the deposit</td><td class="r">− {{ $aed($s->applied) }}</td></tr>
        <tr class="grand"><td></td><td>Refunded to you</td><td class="r">{{ $aed($s->refunded) }}</td></tr>
        @if ((float) $s->to_collect > 0)<tr><td></td><td><strong>Still to pay</strong></td><td class="r"><strong>{{ $aed($s->to_collect) }}</strong></td></tr>@endif
    </table>
    <table class="info" style="margin-top:12px">
        @if ($s->refund_method)<tr><td class="k">Refunded by</td><td>{{ \App\Enums\PaymentMethod::tryFrom($s->refund_method)?->getLabel() ?? $s->refund_method }}</td></tr>@endif
        @if ($s->preauth_release_ref)<tr><td class="k">Card hold released</td><td>{{ $s->preauth_release_ref }}</td></tr>@endif
        @if ($s->note)<tr><td class="k">Note</td><td>{{ $s->note }}</td></tr>@endif
    </table>
@endsection
@section('foot-note')Deposit statement for booking {{ $b->reference }}.@endsection
