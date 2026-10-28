@extends('pdf.layout')
@section('title', 'Return statement '.$b->reference)
@section('doc-title', $b->isCar() ? 'Return & Damage Statement' : 'End of Service Statement')

@php
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $fuel = \App\Models\Booking::FUEL_LEVELS;
    $reports = $b->damageReports()->whereIn('status', ['charged', 'no_charge', 'open'])->get();
    $deposit = $b->transactions()->where('category', 'deposit')->where('status', \App\Enums\PaymentStatus::Paid)->sum('amount');
    $refunded = $b->transactions()->where('category', 'deposit_refund')->where('status', \App\Enums\PaymentStatus::Paid)->sum('amount');
@endphp

@section('meta')
    <tr><td class="k">Booking</td><td class="r"><strong>{{ $b->reference }}</strong></td></tr>
    <tr><td class="k">Date</td><td class="r">{{ ($b->returned_at ?? now())->format('d M Y') }}</td></tr>
@endsection

@section('content')
    <div class="box" style="margin-top:16px">
        <div class="label">{{ $b->customer?->name }}</div>
        <div class="strong">{{ $b->bookable?->name }}@if ($b->isCar() && $b->bookable?->registration_number) · {{ $b->bookable->registration_number }}@endif</div>
    </div>

    <table class="lines">
        <thead><tr><th></th><th>Start</th><th>Return</th></tr></thead>
        <tbody>
            <tr><td class="muted">Time</td><td>{{ $b->delivered_at?->format('d M Y H:i') ?? '-' }}</td><td>{{ $b->returned_at?->format('d M Y H:i') ?? '-' }} <span class="muted">(due {{ $b->end_at?->format('d M H:i') }})</span></td></tr>
            @if ($b->isCar())
                <tr><td class="muted">Odometer</td><td>{{ $b->delivery_km ? number_format($b->delivery_km).' km' : '-' }}</td><td>{{ $b->return_km ? number_format($b->return_km).' km' : '-' }}@if ($b->delivery_km && $b->return_km) <span class="muted">({{ number_format($b->return_km - $b->delivery_km) }} km driven)</span>@endif</td></tr>
                <tr><td class="muted">Fuel</td><td>{{ $fuel[$b->delivery_fuel] ?? '-' }}</td><td>{{ $fuel[$b->return_fuel] ?? '-' }}</td></tr>
            @endif
            <tr><td class="muted">Condition</td><td>{{ $b->delivery_notes ? \Illuminate\Support\Str::limit($b->delivery_notes, 120) : 'As handed over' }}</td><td>{{ \App\Models\Booking::CONDITIONS[$b->return_condition] ?? '-' }}</td></tr>
        </tbody>
    </table>

    @if ($reports->isNotEmpty())
        <h3>Damage reported</h3>
        <table class="lines" style="margin-top:4px">
            <thead><tr><th>What</th><th style="width:22%">Outcome</th><th class="r" style="width:18%">Charge</th></tr></thead>
            <tbody>
                @foreach ($reports as $r)
                    <tr><td>{{ $r->description }}</td><td>{{ $r->statusLabel() }}</td><td class="r">{{ $r->status === 'charged' ? $aed($r->charge_amount) : '-' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>Charges on return</h3>
    <table class="lines" style="margin-top:4px">
        <tbody>
            @forelse ((array) $b->charges as $key => $amount)
                @if ((float) $amount > 0)<tr><td>{{ \App\Support\Options::chargeLabel($key) }}</td><td class="r" style="width:24%">{{ $aed($amount) }}</td></tr>@endif
            @empty
                <tr><td class="muted">No extra charges.</td><td></td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr><td style="width:55%"></td><td class="muted">Booking total incl. charges</td><td class="r">{{ $aed($b->amountDue()) }}</td></tr>
        <tr><td></td><td class="muted">Paid</td><td class="r">{{ $aed($b->paid_amount) }}</td></tr>
        <tr class="grand"><td></td><td>Still to pay</td><td class="r">{{ $aed($b->balance) }}</td></tr>
        @if ($deposit > 0)
            <tr><td></td><td class="muted">Security deposit received</td><td class="r">{{ $aed($deposit) }}</td></tr>
            <tr><td></td><td class="muted">Deposit returned or used</td><td class="r">{{ $aed($refunded) }}</td></tr>
            <tr><td></td><td class="muted">Deposit still held</td><td class="r">{{ $aed(max(0, $deposit - $refunded)) }}</td></tr>
        @endif
    </table>

    @if ($b->return_notes)<h3>Notes</h3><div class="note">{{ $b->return_notes }}</div>@endif

    <table class="sign">
        <tr>
            <td><div class="line">Checked by {{ $b->receivedBy?->name ?? '' }}</div></td>
            <td><div class="line">Customer</div></td>
        </tr>
    </table>
@endsection
