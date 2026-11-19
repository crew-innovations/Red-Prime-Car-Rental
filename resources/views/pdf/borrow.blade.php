@extends('pdf.layout')
@php
    /** 19 Nov (suggestion 7): vehicle borrow agreement with the supplier a car is hired from. $b = Booking with supplier. */
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $sup = $b->supplier;
    $car = $b->bookable;
    $profile = \App\Support\Documents\CompanyProfile::values();
    $fuel = fn ($v) => $v === null || $v === '' ? '—' : \App\Support\Fuel\Fuel::label($v);
@endphp
@section('title', 'Vehicle borrow agreement '.$b->reference)
@section('doc-title', 'Vehicle Borrow Agreement')
@section('meta')
    <tr><td class="k">Reference</td><td class="r"><strong>{{ $b->reference }}</strong></td></tr>
    <tr><td class="k">Date</td><td class="r">{{ today()->format('d M Y') }}</td></tr>
@endsection
@section('content')
    <table class="parties" style="margin-top:14px">
        <tr>
            <td style="padding-right:8px"><div class="box"><div class="label">Lender (owner of the vehicle)</div><div class="strong">{{ $sup?->name }}</div>
                <div class="muted">{{ collect([$sup?->contact_person, $sup?->phone, $sup?->email])->filter()->implode(' · ') }}</div>
                @if ($sup?->trn)<div class="muted">TRN {{ $sup->trn }}</div>@endif @if ($sup?->address)<div class="muted">{{ $sup->address }}</div>@endif</div></td>
            <td style="padding-left:8px"><div class="box"><div class="label">Borrower</div><div class="strong">{{ $profile['legal_name'] }}</div>
                <div class="muted">{{ collect([$profile['licence'] ? 'Trade licence '.$profile['licence'] : null, $profile['trn'] ? 'TRN '.$profile['trn'] : null])->filter()->implode(' · ') }}</div></div></td>
        </tr>
    </table>
    <h3><span class="rule"></span>Vehicle and period</h3>
    <table class="info">
        <tr><td class="k">Vehicle</td><td>{{ $car?->name }}{{ $car?->registration_number ? ' · Plate '.$car->registration_number : '' }}{{ $car?->year ? ' · '.$car->year : '' }}{{ $car?->colour ? ' · '.$car->colour : '' }}</td></tr>
        <tr><td class="k">From</td><td>{{ $b->start_at?->format('l, d M Y · H:i') }}</td></tr>
        <tr><td class="k">Until</td><td>{{ $b->end_at?->format('l, d M Y · H:i') }}</td></tr>
        <tr><td class="k">Agreed cost</td><td>{{ $b->supplier_cost !== null ? $aed($b->supplier_cost) : 'As per the agreed rates' }}{{ $sup?->payment_terms ? ' · '.$sup->payment_terms : '' }}</td></tr>
        @if ($b->delivered_at)<tr><td class="k">Out</td><td>{{ $b->delivery_km !== null ? number_format($b->delivery_km).' km' : '—' }} · fuel {{ $fuel($b->delivery_fuel_8 ?? $b->delivery_fuel) }} · {{ $b->delivered_at->format('d M Y H:i') }}</td></tr>@endif
        @if ($b->returned_at)<tr><td class="k">Back</td><td>{{ $b->return_km !== null ? number_format($b->return_km).' km' : '—' }} · fuel {{ $fuel($b->return_fuel_8 ?? $b->return_fuel) }} · {{ $b->returned_at->format('d M Y H:i') }}</td></tr>@endif
    </table>
    <h3><span class="rule"></span>Terms</h3>
    <div class="note">{{ filled(\App\Models\Setting::read('borrow_terms')) ? \App\Models\Setting::read('borrow_terms') : 'The lender confirms the vehicle is registered, insured and fit for rental for the whole period. Traffic fines, Salik and damage during the period are settled between the parties as agreed.' }}</div>
    <table class="sign"><tr><td><div class="line">For the lender</div></td><td><div class="line">For the borrower</div></td></tr></table>
@endsection
@section('foot-note')Vehicle borrow agreement {{ $b->reference }}.@endsection
