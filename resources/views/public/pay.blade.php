@extends('public.layout')
@section('title', 'Payment '.$r->number)
@section('tag', 'Secure payment')
@section('content')
    @php
        $paid = $r->isPaid();
        $waiting = ! $paid && $r->status === 'processing' && $returned;
        $closed = ! $paid && ! $r->isPayable();
        $labels = ['stripe' => 'Pay by card / Apple Pay', 'paymob' => 'Pay by card (Paymob)', 'tabby' => 'Split in 4 with Tabby', 'tamara' => 'Pay later with Tamara', 'paypal' => 'Pay with PayPal'];
    @endphp
    @if ($waiting)
        <meta http-equiv="refresh" content="6">
    @endif

    @if ($paid)
        <div class="alert ok">Thank you. Your payment of AED {{ number_format((float) $r->amount, 2) }} was received{{ $r->paid_at ? ' on '.$r->paid_at->format('d M Y') : '' }}.</div>
    @elseif ($waiting)
        <div class="alert ok">Thank you. We are confirming your payment with the provider. This page refreshes by itself.</div>
    @elseif ($r->status === 'review')
        <div class="alert bad">We received a payment for this link and our team is checking it. You don't need to pay again.</div>
    @elseif ($cancelled || $failed)
        <div class="alert bad">{{ $failed ? 'The payment did not go through.' : 'The payment was cancelled.' }} No money was taken by us. You can try again below.</div>
    @endif
    @error('pay')<div class="alert bad">{{ $message }}</div>@enderror

    <div class="card">
        <span class="pill {{ $paid ? 'ok' : ($closed ? 'bad' : 'warn') }}">{{ $paid ? 'Paid' : ($closed ? 'Closed' : 'To pay') }}</span>
        <h1 style="margin-top:10px">AED {{ number_format((float) $r->amount, 2) }}</h1>
        <p class="muted" style="margin:0">{{ $r->description }}</p>
        <table style="margin-top:14px">
            <tr><td class="muted">Reference</td><td class="r">{{ $r->number }}</td></tr>
            @if ($r->booking)<tr><td class="muted">Booking</td><td class="r">{{ $r->booking->reference }}</td></tr>
            @elseif ($r->quotation)<tr><td class="muted">Quotation</td><td class="r">{{ $r->quotation->number }}</td></tr>@endif
            @if ($r->customerName())<tr><td class="muted">Name</td><td class="r">{{ $r->customerName() }}</td></tr>@endif
            @if ($r->purpose === 'security_deposit')<tr><td colspan="2" class="muted">Refundable security deposit: it is not a charge and is returned as agreed in your rental terms.</td></tr>@endif
            @if ($r->expires_at && ! $paid)<tr><td class="muted">Link valid until</td><td class="r">{{ $r->expires_at->format('d M Y H:i') }}</td></tr>@endif
        </table>
    </div>

    @if (! $paid && ! $waiting && $r->status !== 'review')
        <div class="card">
            @if ($closed)
                <h2>This payment link is closed</h2>
                <p class="muted">Please contact RED PRIME RENTALS for a new link.</p>
            @elseif (! $choices)
                <h2>Online payment is not available right now</h2>
                <p class="muted">Please contact RED PRIME RENTALS to pay another way.</p>
            @else
                <h2>Choose how to pay</h2>
                <div style="display:flex;flex-direction:column;gap:10px">
                    @foreach ($choices as $key => $g)
                        <form method="post" action="{{ route('pay.start', ['token' => $r->token, 'provider' => $key]) }}">
                            @csrf
                            <button class="btn {{ $loop->first ? 'btn-primary' : 'btn-ghost' }}" style="width:100%" type="submit">{{ $labels[$key] ?? $g->label() }}</button>
                        </form>
                    @endforeach
                </div>
                <p class="muted" style="margin-top:12px">You pay on the provider's secure page. RED PRIME RENTALS never sees or stores your card details.@if ($bnpl = array_intersect(array_keys($choices), ['tabby', 'tamara'])) Instalment plans are approved by {{ implode(' / ', array_map('ucfirst', $bnpl)) }}.@endif</p>
            @endif
        </div>
    @endif
@endsection
