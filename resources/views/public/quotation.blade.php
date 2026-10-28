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
                @if (! empty($line['waived']))
                    <tr><td>{{ $line['label'] ?? 'Extra' }} <span class="pill ok">Waived</span></td><td class="r"><s class="muted">{{ $aed($line['standard'] ?? 0) }}</s> {{ $aed(0) }}</td></tr>
                @else
                    <tr><td>{{ $line['label'] ?? 'Extra' }}@if (! empty($line['qty']) && (float) $line['qty'] != 1) <span class="muted">({{ \App\Support\Pricing\PriceEngine::qtyText((float) $line['qty']) }} × {{ $aed($line['unit_price'] ?? 0) }})</span>@endif</td><td class="r">{{ $aed($line['amount'] ?? 0) }}</td></tr>
                @endif
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

    @if (session('accepted'))
        <div class="card" style="border-color:#86efac">
            <h2>Thank you, the quotation is accepted</h2>
            <p class="muted" style="margin:0 0 10px">Accepted {{ $q->accepted_at?->format('d M Y, H:i') }}. This does not book the {{ $q->service_type === \App\Enums\ServiceType::Car ? 'car' : 'service' }} yet: our team will now check availability and confirm your booking.</p>
            @if (session('documents_url'))
                <p style="margin:0 0 10px"><strong>Next step:</strong> please upload your documents ({{ implode(', ', array_map(fn ($t) => \App\Support\Options::documentLabel($t), (array) session('missing'))) }}).</p>
                <a class="btn btn-primary" href="{{ session('documents_url') }}">Upload documents</a>
            @elseif (session('missing'))
                <p style="margin:0">Our team will ask you for your documents.</p>
            @else
                <p style="margin:0">We have all the documents we need.</p>
            @endif
        </div>
    @elseif (session('declined'))
        <div class="card"><h2>Quotation declined</h2><p class="muted" style="margin:0">Thank you for letting us know. Reply on WhatsApp any time if you would like a new offer.</p></div>
    @elseif (! $q->acceptProblem())
        <div class="card no-print" id="accept">
            <h2>Accept this quotation</h2>
            @if ($errors->any())
                <div class="alert bad">{{ $errors->first() }}</div>
            @endif
            <form method="post" action="{{ route('quotations.public.accept', ['token' => $q->public_token]) }}">
                @csrf
                <label for="accept_name">Your full name</label>
                <input id="accept_name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" style="width:100%;font:15px Poppins,sans-serif;padding:12px;border:1px solid var(--line);border-radius:12px">
                <label style="display:flex;gap:10px;align-items:flex-start;font-weight:400;margin-top:14px">
                    <input type="checkbox" name="agree" value="1" required style="margin-top:4px">
                    <span>I accept the price{{ $q->terms ? ' and the terms' : '' }} shown above for {{ $q->customerName() }}.</span>
                </label>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
                    <button class="btn btn-primary" type="submit">Accept quotation</button>
                </div>
            </form>
            <details style="margin-top:14px">
                <summary class="muted" style="cursor:pointer">Not for you? Decline</summary>
                <form method="post" action="{{ route('quotations.public.decline', ['token' => $q->public_token]) }}" style="margin-top:10px">
                    @csrf
                    <label for="decline_reason">Reason (optional)</label>
                    <input id="decline_reason" name="reason" maxlength="200" style="width:100%;font:15px Poppins,sans-serif;padding:12px;border:1px solid var(--line);border-radius:12px">
                    <button class="btn btn-ghost" type="submit" style="margin-top:10px">Decline quotation</button>
                </form>
            </details>
        </div>
    @endif

    <div class="card">
        <div style="font-weight:600">{{ $s['company_name'] ?? 'RED PRIME RENTALS' }}</div>
        <div class="muted" style="white-space:pre-line">{{ collect([$s['company_address'] ?? null, $s['company_phone'] ?? null, $s['company_email'] ?? null, ! empty($s['company_trn']) && ! empty($s['vat_enabled']) ? 'TRN '.$s['company_trn'] : null])->filter()->implode("\n") }}</div>
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
