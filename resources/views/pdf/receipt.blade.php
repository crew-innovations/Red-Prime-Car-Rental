@extends('pdf.layout')
@section('title', 'Receipt '.$number)
@section('doc-title', $t->category === 'deposit' ? 'Deposit Receipt' : 'Receipt')

@php
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $b = $t->booking;
    $customer = $b?->customer ?? $t->customer;
@endphp

@section('meta')
    <tr><td class="k">Receipt</td><td class="r"><strong>{{ $number }}</strong></td></tr>
    <tr><td class="k">Date</td><td class="r">{{ $t->date?->format('d M Y') }}</td></tr>
    @if ($b)<tr><td class="k">Booking</td><td class="r">{{ $b->reference }}</td></tr>@endif
@endsection

@section('content')
    <div class="box" style="margin-top:18px">
        <table>
            <tr>
                <td>
                    <div class="label">Received from</div>
                    <div class="strong">{{ $customer?->name ?? '-' }}</div>
                    @if ($customer?->company_name)<div>{{ $customer->company_name }}</div>@endif
                </td>
                <td class="r">
                    <div class="label">Amount received</div>
                    <div style="font-size:20px;font-weight:bold;color:#d7141a">{{ $aed($t->amount) }}</div>
                    <div class="muted">{{ $t->method?->getLabel() ?? '' }}{{ $t->reference ? ' · ref '.$t->reference : '' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="info" style="margin-top:14px">
        <tr><td class="k">For</td><td>{{ $t->description ?: $t->categoryLabel() }}</td></tr>
        @if ($b)
            <tr><td class="k">{{ $b->service_type?->getLabel() }}</td><td>{{ $b->bookable?->name }} · {{ $b->start_at?->format('d M Y H:i') }} – {{ $b->end_at?->format('d M Y H:i') }}</td></tr>
            @if ($t->category !== 'deposit')
                <tr><td class="k">Booking total</td><td>{{ $aed($b->amountDue()) }}</td></tr>
                <tr><td class="k">Paid in total</td><td>{{ $aed($b->paid_amount) }}</td></tr>
                <tr><td class="k">Still to pay</td><td><strong>{{ $aed($b->balance) }}</strong></td></tr>
            @endif
        @endif
    </table>

    @if ($t->category === 'deposit')
        <div class="note">This is a refundable security deposit. It is not payment for the rental and is returned after the {{ $b?->isCar() ? 'car' : 'booking' }} is checked, minus any approved charges.</div>
    @endif

    <table class="sign">
        <tr><td><div class="line">Received by {{ $t->user?->name ?? '' }}</div></td><td></td></tr>
    </table>
@endsection

@section('foot-note')This receipt confirms money received. It is not a tax invoice.@endsection
