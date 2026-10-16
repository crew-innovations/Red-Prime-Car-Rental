@extends('public.layout')
@section('title', 'Quotation '.$q->number)
@section('tag', 'Quotation')

@php
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $item = $q->bookable;
    $expired = $q->status === \App\Enums\QuotationStatus::Expired || ($q->valid_until && $q->valid_until->isPast() && ! $q->valid_until->isToday());
    $unit = \App\Models\Booking::rateLabel($q->rate_type);
@endphp

@section('content')
    <div class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
                <h1>Quotation {{ $q->number }}</h1>
                <div class="muted">For {{ $q->customerName() }} · issued {{ ($q->sent_at ?? $q->created_at)->format('d M Y') }}</div>
            </div>
            <div style="text-align:right">
                @if ($q->status === \App\Enums\QuotationStatus::Accepted)
                    <span class="pill ok">Accepted</span>
                @elseif ($expired)
                    <span class="pill bad">Expired</span>
                @elseif ($q->valid_until)
                    <span class="pill info">Valid until {{ $q->valid_until->format('d M Y') }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="card">
        <h2>{{ $item?->name ?? $q->service_type?->getLabel() }}</h2>
        <table>
            <tr><td class="muted">Service</td><td class="r">{{ $q->service_type?->getLabel() }}{{ $q->drive_mode ? ' · '.$q->drive_mode->getLabel() : '' }}</td></tr>
            @if ($q->start_at)
                <tr><td class="muted">{{ $q->service_type === \App\Enums\ServiceType::Car ? 'Pick-up' : 'Start' }}</td><td class="r">{{ $q->start_at->format('D, d M Y · H:i') }}</td></tr>
            @endif
            @if ($q->end_at)
                <tr><td class="muted">{{ $q->service_type === \App\Enums\ServiceType::Car ? 'Return' : 'End' }}</td><td class="r">{{ $q->end_at->format('D, d M Y · H:i') }}</td></tr>
            @endif
            @if ($q->guests)
                <tr><td class="muted">Guests</td><td class="r">{{ $q->guests }}</td></tr>
            @endif
            @if ($q->pickup_location)
                <tr><td class="muted">Location</td><td class="r">{{ $q->pickup_location }}</td></tr>
            @endif
        </table>
    </div>

    <div class="card">
        <h2>Price</h2>
        <table>
            <tr>
                <td>Rental{{ $q->quantity && $q->rate !== null ? ' ('.rtrim(rtrim(number_format((float) $q->quantity, 2), '0'), '.').' × '.$aed($q->rate).' / '.$unit.')' : '' }}</td>
                <td class="r">{{ $aed($q->base_amount) }}</td>
            </tr>
            @foreach ($q->extras ?? [] as $line)
                <tr><td>{{ $line['label'] ?? 'Extra' }}</td><td class="r">{{ $aed($line['amount'] ?? 0) }}</td></tr>
            @endforeach
            @if ((float) $q->discount > 0)
                <tr><td>Discount</td><td class="r">− {{ $aed($q->discount) }}</td></tr>
            @endif
            @if ((float) $q->tax_amount > 0)
                <tr><td>VAT {{ rtrim(rtrim(number_format((float) $q->tax_rate, 2), '0'), '.') }}%{{ $q->tax_inclusive ? ' (included)' : '' }}</td><td class="r">{{ $aed($q->tax_amount) }}</td></tr>
            @endif
            <tr><td><strong>Total</strong></td><td class="r"><strong style="font-size:18px">{{ $aed($q->total) }}</strong></td></tr>
            @if ((float) $q->advance_required > 0)
                <tr><td class="muted">Advance to confirm</td><td class="r">{{ $aed($q->advance_required) }}</td></tr>
            @endif
            @if ((float) $q->security_deposit > 0)
                <tr><td class="muted">Refundable security deposit</td><td class="r">{{ $aed($q->security_deposit) }}</td></tr>
            @endif
        </table>
    </div>

    @if ($q->customer_notes)
        <div class="card"><h2>Notes</h2><div style="white-space:pre-line;font-size:14px">{{ $q->customer_notes }}</div></div>
    @endif
    @if ($q->terms)
        <div class="card"><h2>Terms</h2><div class="muted" style="white-space:pre-line">{{ $q->terms }}</div></div>
    @endif

    <div class="card">
        <div style="font-weight:600">{{ $s['company_name'] ?? 'RED PRIME RENTALS' }}</div>
        <div class="muted" style="white-space:pre-line">{{ collect([$s['company_address'] ?? null, $s['company_phone'] ?? null, $s['company_email'] ?? null, ! empty($s['company_trn']) ? 'TRN '.$s['company_trn'] : null])->filter()->implode("\n") }}</div>
        @if (! empty($s['bank_details']))
            <div class="muted" style="white-space:pre-line;margin-top:8px">{{ $s['bank_details'] }}</div>
        @endif
    </div>

    <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary" onclick="window.print()">Download PDF</button>
        @if (! empty($s['company_phone']))
            <a class="btn btn-ghost" href="{{ \App\Support\Phone::whatsappUrl($s['company_phone'], 'Hi, about quotation '.$q->number.': ') }}">Reply on WhatsApp</a>
        @endif
    </div>
@endsection
