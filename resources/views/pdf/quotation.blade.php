@extends('pdf.layout')
@section('title', 'Quotation '.$q->number)
@section('doc-title', 'Quotation')

@php
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $item = $q->bookable;
    $isCar = $q->service_type === \App\Enums\ServiceType::Car;
    $isYacht = $q->service_type === \App\Enums\ServiceType::Yacht;
    $hours = $q->start_at && $q->end_at ? max(1, (int) ceil($q->start_at->diffInMinutes($q->end_at) / 60)) : null;
    $lines = collect((array) $q->extras);
    $s = \App\Models\Setting::values();
    $status = match (true) {
        $q->status === \App\Enums\QuotationStatus::Accepted => ['Accepted '.$q->accepted_at?->format('d M Y'), 'ok'],
        $q->status === \App\Enums\QuotationStatus::Rejected => ['Declined', 'bad'],
        $q->status === \App\Enums\QuotationStatus::Expired || ($q->valid_until && $q->valid_until->lt(today())) => ['Expired', 'bad'],
        default => ['Valid until '.$q->valid_until?->format('d M Y'), 'info'],
    };
@endphp

@section('meta')
    <tr><td class="k">Number</td><td class="r"><strong>{{ $q->number }}</strong></td></tr>
    <tr><td class="k">Issued</td><td class="r">{{ ($q->sent_at ?? $q->created_at)->format('d M Y') }}</td></tr>
    @if ($q->valid_until)<tr><td class="k">Valid until</td><td class="r">{{ $q->valid_until->format('d M Y') }}</td></tr>@endif
    <tr><td class="k">Status</td><td class="r"><span class="pill pill-{{ $status[1] }}">{{ $status[0] }}</span></td></tr>
@endsection

@section('content')
    <table class="parties" style="margin-top:16px">
        <tr>
            <td style="padding-right:8px">
                <div class="box">
                    <div class="label">Prepared for</div>
                    <div class="strong">{{ $q->customerName() }}</div>
                    @if ($q->customer?->company_name && $q->customer->company_name !== $q->customerName())<div>{{ $q->customer->company_name }}</div>@endif
                    <div class="muted">{{ collect([$q->customerPhone(), $q->customerEmail()])->filter()->implode(' · ') }}</div>
                    @if ($q->customer?->address)<div class="muted">{{ $q->customer->address }}</div>@endif
                </div>
            </td>
            <td style="padding-left:8px">
                <div class="box">
                    <div class="label">{{ $q->service_type?->getLabel() }}</div>
                    <div class="strong">{{ $item?->name ?? $q->service_type?->getLabel() }}</div>
                    @if ($isCar)
                        <div class="muted">{{ collect([$item?->year, $item?->type, $q->drive_mode?->getLabel()])->filter()->implode(' · ') }}</div>
                    @elseif ($isYacht)
                        <div class="muted">{{ collect([$item?->length_ft ? $item->length_ft.' ft' : null, $item?->max_guests ? 'up to '.$item->max_guests.' guests' : null, $q->crew_mode === 'bareboat' ? 'without crew' : 'with captain & crew'])->filter()->implode(' · ') }}</div>
                    @else
                        <div class="muted">{{ $q->guests ? $q->guests.' guests' : '' }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <h3>Details</h3>
    <table class="info">
        @if ($q->start_at)<tr><td class="k">{{ $isCar ? 'Pick-up' : 'Start' }}</td><td>{{ $q->start_at->format('l, d M Y · H:i') }}</td></tr>@endif
        @if ($q->end_at)<tr><td class="k">{{ $isCar ? 'Return' : 'End' }}</td><td>{{ $q->end_at->format('l, d M Y · H:i') }}@if ($hours) <span class="muted">({{ $hours < 24 ? $hours.' hours' : ceil($hours / 24).' days' }})</span>@endif</td></tr>@endif
        @if ($q->guests && ! $isCar)<tr><td class="k">Guests</td><td>{{ $q->guests }}</td></tr>@endif
        @if ($isCar)
            <tr><td class="k">Getting the car</td><td>{{ $q->delivery_method === 'delivery' ? 'Delivered to you'.($q->pickup_location ? ': '.$q->pickup_location : '') : ($q->pickup_location ?: 'Collect from our office') }}</td></tr>
            <tr><td class="k">Returning the car</td><td>{{ $q->return_method === 'collection' ? 'We collect it' : 'Return to our office' }}</td></tr>
            @if ($item?->daily_km_limit)<tr><td class="k">Mileage</td><td>{{ number_format($item->daily_km_limit) }} km per day included @if ($item->extra_km_charge)· then {{ $aed($item->extra_km_charge) }} per km @endif</td></tr>@endif
        @elseif ($q->pickup_location)
            <tr><td class="k">Meeting point</td><td>{{ $q->pickup_location }}</td></tr>
        @endif
        @if ($q->service_type === \App\Enums\ServiceType::Trip && ($item?->description || $item?->itinerary))
            <tr><td class="k">Trip</td><td>{{ \Illuminate\Support\Str::limit(strip_tags((string) $item->description), 400) }}</td></tr>
        @endif
        @if ($q->price_breakdown['base_label'] ?? null)<tr><td class="k">Pricing</td><td>{{ $q->price_breakdown['base_label'] }}</td></tr>@endif
    </table>

    <table class="lines">
        <thead><tr><th>Description</th><th class="r" style="width:24%">Amount</th></tr></thead>
        <tbody>
            <tr>
                <td>{{ $isCar ? 'Rental' : ($isYacht ? 'Charter' : 'Price') }}@if ($q->quantity && $q->rate !== null)<div class="detail">{{ \App\Support\Pricing\PriceEngine::qtyText((float) $q->quantity) }} × {{ $aed($q->rate) }} / {{ \App\Support\Pricing\PriceEngine::unitLabel($q->rate_type) }}</div>@endif</td>
                <td class="r">{{ $aed($q->base_amount) }}</td>
            </tr>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['label'] ?? 'Extra' }}@if (! empty($line['waived'])) <span class="pill pill-ok">Waived</span>@endif
                        @if (! empty($line['qty']) && (float) $line['qty'] != 1)<div class="detail">{{ \App\Support\Pricing\PriceEngine::qtyText((float) $line['qty']) }} × {{ $aed($line['unit_price'] ?? 0) }}</div>@endif</td>
                    <td class="r">@if (! empty($line['waived']))<span class="muted" style="text-decoration:line-through">{{ $aed($line['standard'] ?? 0) }}</span> @endif{{ $aed($line['amount'] ?? 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td style="width:60%"></td><td class="muted">Subtotal</td><td class="r">{{ $aed((float) $q->base_amount + (float) $q->extras_amount) }}</td></tr>
        @if ((float) $q->discount > 0)<tr><td></td><td class="muted">Discount</td><td class="r">− {{ $aed($q->discount) }}</td></tr>@endif
        @if ((float) $q->tax_amount > 0)<tr><td></td><td class="muted">VAT {{ rtrim(rtrim(number_format((float) $q->tax_rate, 2), '0'), '.') }}%{{ $q->tax_inclusive ? ' (included)' : '' }}</td><td class="r">{{ $aed($q->tax_amount) }}</td></tr>@endif
        <tr class="grand"><td></td><td>Total</td><td class="r">{{ $aed($q->total) }}</td></tr>
        @if ((float) $q->advance_required > 0)<tr><td></td><td class="muted">Advance to confirm</td><td class="r">{{ $aed($q->advance_required) }}</td></tr>@endif
        @if ((float) $q->security_deposit > 0)<tr><td></td><td class="muted">Security deposit (refundable, not part of the price)</td><td class="r">{{ $aed($q->security_deposit) }}</td></tr>@endif
    </table>

    @if ($q->customer_notes)<h3>Notes</h3><div class="note">{{ $q->customer_notes }}</div>@endif
    @if ($q->terms || ($isCar && $item?->terms) || ($isYacht && $item?->terms))
        <h3>Terms</h3>
        <div class="note muted">{{ trim(($q->terms ?? '')."\n".(($isCar || $isYacht) ? ($item?->terms ?? '') : '')) }}</div>
    @endif
    @if (! empty($s['policy_late_return']))<h3>Late return</h3><div class="note muted">{{ $s['policy_late_return'] }}</div>@endif
    @if (! empty($s['policy_cancellation']))<h3>Cancellation and changes</h3><div class="note muted">{{ $s['policy_cancellation'] }}</div>@endif
    @if (! empty($s['bank_details']))<h3>Payment</h3><div class="note">{{ $s['bank_details'] }}</div>@endif

    <h3>How to accept</h3>
    @if ($q->status === \App\Enums\QuotationStatus::Accepted)
        <div class="note">Accepted on {{ $q->accepted_at?->format('d M Y, H:i') }}{{ $q->accepted_name ? ' by '.$q->accepted_name : '' }}. Your booking is confirmed separately, once availability is checked.</div>
    @else
        <div class="note">Open your quotation link and press "Accept quotation", or reply to us on WhatsApp. Accepting does not book the {{ $isCar ? 'car' : 'service' }} yet: we confirm the booking after checking availability.@if ($q->sent_at)

{{ $q->publicUrl() }}@endif</div>
    @endif
@endsection

@section('foot-note')This quotation is not an invoice.@endsection
