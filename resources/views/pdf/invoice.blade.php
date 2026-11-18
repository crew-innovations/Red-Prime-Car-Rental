@extends('pdf.layout')
@section('title', $i->title().' '.$i->number)
@section('doc-title', $i->title())

@php
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $seller = (array) $i->seller;
    $buyer = (array) $i->buyer;
    $paid = $i->booking && ! $i->isCreditNote() ? (float) $i->booking->paid_amount : null;
@endphp

@section('meta')
    <tr><td class="k">Number</td><td class="r"><strong>{{ $i->number }}</strong></td></tr>
    <tr><td class="k">Date</td><td class="r">{{ $i->issued_on->format('d M Y') }}</td></tr>
    @if ($i->supply_date)<tr><td class="k">Date of supply</td><td class="r">{{ $i->supply_date->format('d M Y') }}</td></tr>@endif
    @if ($i->booking)<tr><td class="k">Booking</td><td class="r">{{ $i->booking->reference }}</td></tr>@endif
    @if ($i->invoice)<tr><td class="k">For invoice</td><td class="r">{{ $i->invoice->number }}</td></tr>@endif
    @if ($i->status === 'void')<tr><td class="k">Status</td><td class="r"><span class="pill pill-bad">Void</span></td></tr>@endif
@endsection

@section('content')
    <table class="parties" style="margin-top:16px">
        <tr>
            <td style="padding-right:8px">
                <div class="box">
                    <div class="label">From</div>
                    <div class="strong">{{ $seller['name'] ?? '' }}</div>
                    @if (! empty($seller['address']))<div class="muted">{{ $seller['address'] }}</div>@endif
                    @if (! empty($seller['licence']))<div class="muted">Trade licence {{ $seller['licence'] }}</div>@endif
                    @if ($i->is_tax_invoice && ! empty($seller['trn']))<div><strong>TRN {{ $seller['trn'] }}</strong></div>@endif
                </div>
            </td>
            <td style="padding-left:8px">
                <div class="box">
                    <div class="label">Bill to</div>
                    <div class="strong">{{ $buyer['name'] ?? '' }}</div>
                    @if (! empty($buyer['contact']))<div>Attn: {{ $buyer['contact'] }}</div>@endif
                    @if (! empty($buyer['address']))<div class="muted">{{ $buyer['address'] }}</div>@endif
                    <div class="muted">{{ collect([$buyer['phone'] ?? null, $buyer['email'] ?? null])->filter()->implode(' · ') }}</div>
                    @if ($i->is_tax_invoice && ! empty($buyer['trn']))<div>TRN {{ $buyer['trn'] }}</div>@endif
                </div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead><tr><th>Description</th><th class="r" style="width:12%">Qty</th><th class="r" style="width:18%">Unit price</th><th class="r" style="width:18%">Amount</th></tr></thead>
        <tbody>
            @foreach ((array) $i->lines as $l)
                <tr>
                    <td>{{ $l['label'] ?? '' }}@if (! empty($l['detail']))<div class="detail">{{ $l['detail'] }}</div>@endif</td>
                    <td class="r">{{ \App\Support\Pricing\PriceEngine::qtyText((float) ($l['qty'] ?? 1)) }}</td>
                    <td class="r">{{ number_format((float) ($l['unit_price'] ?? 0), 2) }}</td>
                    <td class="r">{{ number_format((float) ($l['amount'] ?? 0), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td style="width:55%"></td><td class="muted">Subtotal</td><td class="r">{{ $aed($i->subtotal) }}</td></tr>
        @if ((float) $i->discount > 0)<tr><td></td><td class="muted">Discount{{ ($ib = (float) $i->subtotal - (float) collect((array) $i->lines)->where('charge', true)->sum('amount')) > 0 ? ' ('.\App\Support\Pricing\Discounts::percentText(round((float) $i->discount / $ib * 100, 2)).')' : '' }}</td><td class="r">− {{ $aed($i->discount) }}</td></tr>@endif
        @if ($i->is_tax_invoice)
            <tr><td></td><td class="muted">VAT {{ rtrim(rtrim(number_format((float) $i->tax_rate, 2), '0'), '.') }}%{{ $i->tax_inclusive ? ' (included)' : '' }}</td><td class="r">{{ $aed($i->tax_amount) }}</td></tr>
        @endif
        <tr class="grand"><td></td><td>{{ $i->isCreditNote() ? 'Credit' : 'Total' }} ({{ $i->currency }})</td><td class="r">{{ $aed($i->total) }}</td></tr>
        @if ($paid !== null)
            <tr><td></td><td class="muted">Paid</td><td class="r">{{ $aed(min($paid, (float) $i->total)) }}</td></tr>
            <tr><td></td><td><strong>Balance due</strong></td><td class="r"><strong>{{ $aed(max(0, (float) $i->total - $paid)) }}</strong></td></tr>
        @endif
    </table>

    @if ($i->notes)<div class="note">{{ $i->notes }}</div>@endif
    @if ($i->status === 'void')<div class="note"><span class="pill pill-bad">Void</span> {{ $i->void_reason }}</div>@endif
    @if (! $i->isCreditNote() && ! empty(\App\Models\Setting::read('bank_details')))<h3>Payment</h3><div class="note">{{ \App\Models\Setting::read('bank_details') }}</div>@endif
@endsection

@section('foot-note'){{ $i->is_tax_invoice ? 'Amounts in AED.' : 'Not a tax invoice: VAT is not charged.' }}@endsection
