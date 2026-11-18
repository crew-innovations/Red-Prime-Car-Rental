@extends('pdf.layout')
@section('title', 'Booking confirmation '.$b->reference)
@section('doc-title', 'Booking Confirmation')

@php
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $item = $b->bookable;
    $isCar = $b->isCar();
    $s = \App\Models\Setting::values();
    $confirmed = in_array($b->status, [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::InProgress, \App\Enums\BookingStatus::Completed], true);
    $lines = \App\Models\Invoice::linesFor($b);
@endphp

@section('meta')
    <tr><td class="k">Booking</td><td class="r"><strong>{{ $b->reference }}</strong></td></tr>
    <tr><td class="k">Date</td><td class="r">{{ today()->format('d M Y') }}</td></tr>
    @if ($b->quotation)<tr><td class="k">Quotation</td><td class="r">{{ $b->quotation->number }}</td></tr>@endif
    <tr><td class="k">Status</td><td class="r"><span class="pill pill-{{ $confirmed ? 'ok' : 'warn' }}">{{ $b->status->getLabel() }}</span></td></tr>
@endsection

@section('content')
    <table class="parties" style="margin-top:16px">
        <tr>
            <td style="padding-right:8px">
                <div class="box">
                    <div class="label">Customer</div>
                    <div class="strong">{{ $b->customer?->name }}</div>
                    @if ($b->customer?->company_name)<div>{{ $b->customer->company_name }}</div>@endif
                    <div class="muted">{{ collect([$b->customer?->phone, $b->customer?->email])->filter()->implode(' · ') }}</div>
                </div>
            </td>
            <td style="padding-left:8px">
                <div class="box">
                    <div class="label">{{ $b->service_type?->getLabel() }}</div>
                    <div class="strong">{{ $item?->name ?? $b->service_type?->getLabel() }}</div>
                    <div class="muted">
                        @if ($isCar){{ collect([$item?->registration_number, $b->drive_mode?->getLabel()])->filter()->implode(' · ') }}
                        @elseif ($b->service_type === \App\Enums\ServiceType::Yacht){{ collect([$b->guests ? $b->guests.' guests' : null, $b->crew_mode === 'bareboat' ? 'without crew' : 'with captain & crew'])->filter()->implode(' · ') }}
                        @else{{ $b->guests ? $b->guests.' guests' : '' }}@endif
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <h3>Your booking</h3>
    <table class="info">
        <tr><td class="k">{{ $isCar ? 'Pick-up' : 'Start' }}</td><td>{{ $b->start_at?->format('l, d M Y · H:i') }}</td></tr>
        <tr><td class="k">{{ $isCar ? 'Return' : 'End' }}</td><td>{{ $b->end_at?->format('l, d M Y · H:i') }}</td></tr>
        @if ($isCar)
            <tr><td class="k">Getting the car</td><td>{{ $b->delivery_method === 'delivery' ? 'Delivered to you'.($b->pickup_location ? ': '.$b->pickup_location : '') : ($b->pickup_location ?: 'Collect from our office') }}</td></tr>
            <tr><td class="k">Returning the car</td><td>{{ $b->return_method === 'collection' ? 'We collect it'.($b->return_location ? ': '.$b->return_location : '') : ($b->return_location ?: 'Return to our office') }}</td></tr>
            @if ($b->drive_mode === \App\Enums\DriveMode::WithDriver && $b->driver)<tr><td class="k">Driver</td><td>{{ $b->driver->name }}</td></tr>@endif
        @elseif ($b->pickup_location)
            <tr><td class="k">Meeting point</td><td>{{ $b->pickup_location }}</td></tr>
        @endif
        @if ($b->captain)<tr><td class="k">Captain</td><td>{{ $b->captain->name }}</td></tr>@endif
    </table>

    <table class="lines">
        <thead><tr><th>Description</th><th class="r" style="width:24%">Amount</th></tr></thead>
        <tbody>
            @foreach ($lines as $l)
                <tr><td>{{ $l['label'] }}@if (! empty($l['detail']))<div class="detail">{{ $l['detail'] }}</div>@endif</td><td class="r">{{ $aed($l['amount']) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        @if ((float) $b->discount > 0)<tr><td style="width:60%"></td><td class="muted">Discount{{ ($dp = \App\Support\Pricing\PriceLines::for($b)['overall_percent']) ? ' ('.\App\Support\Pricing\Discounts::percentText($dp).')' : '' }}</td><td class="r">− {{ $aed($b->discount) }}</td></tr>@endif
        @if ((float) $b->tax_amount > 0)<tr><td style="width:60%"></td><td class="muted">VAT {{ rtrim(rtrim(number_format((float) $b->tax_rate, 2), '0'), '.') }}%{{ $b->tax_inclusive ? ' (included)' : '' }}</td><td class="r">{{ $aed($b->tax_amount) }}</td></tr>@endif
        <tr class="grand"><td style="width:60%"></td><td>Total</td><td class="r">{{ $aed($b->amountDue()) }}</td></tr>
        @if ((float) $b->advance_required > 0 && ! $b->advance_reached_at)<tr><td></td><td class="muted">Advance to confirm{{ $b->advance_percent !== null ? ' ('.\App\Support\Pricing\Discounts::percentText((float) $b->advance_percent).')' : '' }}</td><td class="r">{{ $aed($b->advance_required) }}</td></tr>@endif
        <tr><td></td><td class="muted">Paid so far</td><td class="r">{{ $aed($b->paid_amount) }}</td></tr>
        <tr><td></td><td><strong>Still to pay</strong>@if ((float) $b->balance > 0 && ($bd = \App\Support\Pricing\Advance::balanceDue($b)))<span class="muted"> · due {{ $bd->format('d M Y') }}</span>@endif</td><td class="r"><strong>{{ $aed($b->balance) }}</strong></td></tr>
        @if ((float) $b->security_deposit > 0)<tr><td></td><td class="muted">Security deposit (refundable, collected at the start)</td><td class="r">{{ $aed($b->security_deposit) }}</td></tr>@endif
    </table>

    @php($docs = collect($b->documentChecklist())->reject(fn ($r) => $r[2]))
    @if ($docs->isNotEmpty())
        <h3>Documents still needed</h3>
        <div class="note">{{ $docs->map(fn ($r) => $r[0])->implode(', ') }}</div>
    @endif
    @if ($b->quotation?->terms)<h3>Terms</h3><div class="note muted">{{ $b->quotation->terms }}</div>@endif
    @if (! empty($s['policy_late_return']))<h3>Late return</h3><div class="note muted">{{ $s['policy_late_return'] }}</div>@endif
    @if (! empty($s['policy_cancellation']))<h3>Cancellation and changes</h3><div class="note muted">{{ $s['policy_cancellation'] }}</div>@endif
    @if ((float) $b->balance > 0 && ! empty($s['bank_details']))<h3>Payment</h3><div class="note">{{ $s['bank_details'] }}</div>@endif
@endsection

@section('foot-note')A booking confirmation is not a receipt.@endsection
