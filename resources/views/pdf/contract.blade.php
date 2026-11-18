@extends('pdf.layout')
@php
    /**
     * 18 Nov 2026: Car Rental Agreement (meeting item 1). English with Arabic labels; the terms are
     * RED PRIME's own (Contract settings), copied onto the contract when it is signed.
     * $c = RentalContract, $b = Booking, $version = the version number being saved.
     */
    $pdfText = new \App\Support\Documents\PdfText('both');
    $t = fn (string $key) => $pdfText->t('contract.'.$key);
    $x = fn ($v) => \App\Support\Documents\PdfText::x($v);
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : '—';
    $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y, H:i') : '—';
    $fuel = fn ($v) => $v === null || $v === '' ? '—' : \App\Support\Fuel\Fuel::label($v);
    $car = $b?->bookable;
    $cust = $c->customer;
    $s = \App\Models\Setting::values();
    $lines = $b ? \App\Models\Invoice::linesFor($b) : [];
    $termsEn = $c->signed_at ? $c->terms_en : ($s['contract_terms_en'] ?? null);
    $termsAr = $c->signed_at ? $c->terms_ar : ($s['contract_terms_ar'] ?? null);
    $policies = collect(['policy_fuel' => 'Fuel', 'policy_late_return' => 'Late return', 'policy_fines' => 'Fines, Salik and tolls', 'policy_cancellation' => 'Cancellation and changes'])
        ->filter(fn ($label, $key) => filled($s[$key] ?? null));
    $signature = $c->getFirstMedia('signature');
    $sigData = $signature ? rescue(fn () => 'data:image/png;base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk($signature->disk)->get($signature->getPathRelativeToRoot())), null, false) : null;
    $stamp = \App\Support\Documents\CompanyProfile::stamp();
    $damage = $b ? \App\Models\DamageReport::where('booking_id', $b->id)->orderBy('created_at')->get() : collect();
    $limit = $b?->kmLimitPerDay();
    $rate = $b?->extraKmRate();
    $statusPill = ['draft' => 'warn', 'open' => 'ok', 'closed' => 'info', 'cancelled' => 'bad'][$c->status] ?? 'info';
@endphp
@section('title', 'Rental agreement '.$c->number)
@section('doc-title'){!! $t('title') !!}@endsection
@section('styles')
    .grid2 td { width: 50%; vertical-align: top; }
    .drivers th, .drivers td { padding: 5px 6px; font-size: 7.8px; border-bottom: 1px solid #ececef; text-align: left; vertical-align: top; }
    .drivers th { background: #f4f4f5; font-size: 6.8px; text-transform: uppercase; letter-spacing: .6px; }
    .sig { height: 48px; }
    .sign td { padding-top: 14px; vertical-align: bottom; }
    .stamp { height: 54px; }
    .terms { font-size: 7.4px; line-height: 1.45; color: #3f3f46; }
@endsection

@section('meta')
    <tr><td class="k">{!! $t('contract_no') !!}</td><td class="r"><strong>{{ $c->number }}</strong></td></tr>
    <tr><td class="k">Booking</td><td class="r">{{ $b?->reference }}</td></tr>
    <tr><td class="k">{!! $t('date') !!}</td><td class="r">{{ $c->created_at?->format('d M Y') }}</td></tr>
    <tr><td class="k">{!! $t('version') !!}</td><td class="r">v{{ $version }}</td></tr>
    <tr><td class="k">{!! $t('status') !!}</td><td class="r"><span class="pill pill-{{ $statusPill }}">{{ $c->statusLabel() }}</span></td></tr>
@endsection

@section('content')
    <table class="parties" style="margin-top:14px">
        <tr>
            <td style="padding-right:8px">
                <div class="box">
                    <div class="label">{!! $t('hirer') !!}</div>
                    <div class="strong">{{ $x($cust?->name) }}</div>
                    @if ($cust?->company_name)<div>{{ $x($cust->company_name) }}@if ($cust->trn) · TRN {{ $cust->trn }}@endif</div>@endif
                    <div class="muted">{{ collect([$cust?->phone, $cust?->email])->filter()->implode(' · ') }}</div>
                    <div class="muted">{{ collect([$cust?->nationality, $cust?->id_number ? 'EID '.$cust->id_number : null, $cust?->passport_number ? 'Passport '.$cust->passport_number : null])->filter()->implode(' · ') }}</div>
                    @if ($cust?->address)<div class="muted">{{ $x($cust->address) }}</div>@endif
                    @if ($c->type === 'company' && ($c->lpo_number || $cust?->trade_licence_number))
                        <div class="muted">{{ collect([$cust?->trade_licence_number ? 'Trade licence '.$cust->trade_licence_number : null, $c->lpo_number ? 'LPO '.$c->lpo_number : null])->filter()->implode(' · ') }}</div>
                    @endif
                </div>
            </td>
            <td style="padding-left:8px">
                <div class="box">
                    <div class="label">{!! $t('vehicle') !!}</div>
                    <div class="strong">{{ $car?->name }}</div>
                    <div class="muted">{{ collect([$car?->registration_number ? 'Plate '.$car->registration_number : null, $car?->year, $car?->colour])->filter()->implode(' · ') }}</div>
                    <div class="muted">{{ collect([$b?->drive_mode?->getLabel(), ['short' => 'Short term', 'long' => 'Long term', 'lease' => 'Lease'][$b?->rental_term] ?? null, $c->typeLabel()])->filter()->implode(' · ') }}</div>
                </div>
            </td>
        </tr>
    </table>

    <h3><span class="rule"></span>{!! $t('period') !!}</h3>
    <table class="info">
        <tr><td class="k">{!! $t('pickup') !!}</td><td>{{ $dt($b?->start_at) }} · {{ $b?->delivery_method === 'delivery' ? 'Delivered'.($b->pickup_location ? ': '.$b->pickup_location : '') : ($b?->pickup_location ?: 'Our office') }}</td></tr>
        <tr><td class="k">{!! $t('return') !!}</td><td>{{ $dt($b?->end_at) }} · {{ $b?->return_method === 'collection' ? 'Collected'.($b->return_location ? ': '.$b->return_location : '') : ($b?->return_location ?: 'Our office') }}</td></tr>
        <tr><td class="k">{!! $t('km_allowance') !!}</td><td>{{ $limit ? number_format($limit).' km per day included' : 'No daily limit' }}{{ $limit && $rate ? ', then '.$aed($rate).' per extra km' : '' }}</td></tr>
        @if ($c->billing_period !== 'daily')<tr><td class="k">Billing</td><td>{{ \App\Models\RentalContract::PERIODS[$c->billing_period] ?? $c->billing_period }}{{ $c->auto_renew ? ' · renews automatically' : '' }}</td></tr>@endif
        @if ($b?->drive_mode === \App\Enums\DriveMode::WithDriver && $b->driver)<tr><td class="k">Chauffeur</td><td>{{ $b->driver->name }}</td></tr>@endif
    </table>

    <h3><span class="rule"></span>{!! $t('drivers') !!}</h3>
    <table class="drivers">
        <thead><tr><th></th><th>Name</th><th>{!! $t('nationality') !!}</th><th>{!! $t('dob') !!}</th><th>{!! $t('id_passport') !!}</th><th>{!! $t('licence') !!}</th></tr></thead>
        <tbody>
        @forelse ($c->drivers as $dr)
            <tr>
                <td>{{ $dr->is_main ? 'Main' : 'Additional' }}</td>
                <td><strong>{{ $x($dr->name) }}</strong>@if ($dr->phone)<br><span class="muted">{{ $dr->phone }}</span>@endif</td>
                <td>{{ $dr->nationality ?: '—' }}</td>
                <td>{{ $d($dr->date_of_birth) }}</td>
                <td>{{ $dr->id_number ?: '—' }}@if ($dr->id_expires_on)<br><span class="muted">exp. {{ $d($dr->id_expires_on) }}</span>@endif</td>
                <td>{{ $dr->licence_number ?: '—' }}{{ $dr->licence_country ? ' ('.$dr->licence_country.')' : '' }}@if ($dr->licence_expires_on)<br><span class="muted">exp. {{ $d($dr->licence_expires_on) }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">—</td></tr>
        @endforelse
        </tbody>
    </table>

    @if ($b?->delivered_at || $c->swaps->isNotEmpty())
        <h3><span class="rule"></span>{!! $t('handover') !!}</h3>
        <table class="info">
            <tr><td class="k">{!! $t('km_out') !!}</td><td>{{ $b->delivery_km !== null ? number_format($b->delivery_km).' km' : '—' }} · {!! $t('fuel_out') !!}: {{ $fuel($b->delivery_fuel_8 ?? $b->delivery_fuel) }} · {{ $dt($b->delivered_at) }}</td></tr>
            @if ($b->returned_at)<tr><td class="k">{!! $t('km_in') !!}</td><td>{{ $b->return_km !== null ? number_format($b->return_km).' km' : '—' }} · {!! $t('fuel_in') !!}: {{ $fuel($b->return_fuel_8 ?? $b->return_fuel) }} · {{ $dt($b->returned_at) }}</td></tr>@endif
        </table>
        @if ($c->swaps->isNotEmpty())
            <table class="drivers" style="margin-top:6px">
                <thead><tr><th>{!! $t('replacements') !!}</th><th>From</th><th>To</th><th>Km</th><th>{!! $t('reason') !!}</th></tr></thead>
                <tbody>
                @foreach ($c->swaps as $sw)
                    <tr><td>{{ $dt($sw->swapped_at) }}</td><td>{{ $sw->fromCar?->name }}{{ $sw->fromCar?->registration_number ? ' · '.$sw->fromCar->registration_number : '' }}</td>
                        <td>{{ $sw->toCar?->name }}{{ $sw->toCar?->registration_number ? ' · '.$sw->toCar->registration_number : '' }}</td>
                        <td>{{ $sw->from_km_out !== null ? number_format($sw->from_km_out) : '—' }} → {{ $sw->from_km_in !== null ? number_format($sw->from_km_in) : '—' }} · new {{ $sw->to_km_out !== null ? number_format($sw->to_km_out) : '—' }}</td>
                        <td>{{ $x($sw->reason) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif

    <table class="lines">
        <thead><tr><th>{!! $t('charges') !!}</th><th class="r" style="width:24%">Amount</th></tr></thead>
        <tbody>
        @foreach ($lines as $l)
            <tr><td>{{ $x($l['label']) }}@if (! empty($l['detail']))<div class="detail">{{ $l['detail'] }}</div>@endif</td><td class="r">{{ $aed($l['amount']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        @if ((float) $b?->discount > 0)<tr><td style="width:60%"></td><td class="muted">Discount</td><td class="r">− {{ $aed($b->discount) }}</td></tr>@endif
        @if ((float) $b?->tax_amount > 0)<tr><td style="width:60%"></td><td class="muted">VAT {{ rtrim(rtrim(number_format((float) $b->tax_rate, 2), '0'), '.') }}%{{ $b->tax_inclusive ? ' (included)' : '' }}</td><td class="r">{{ $aed($b->tax_amount) }}</td></tr>@endif
        <tr class="grand"><td style="width:60%"></td><td>{!! $t('total') !!}</td><td class="r">{{ $aed($b?->amountDue()) }}</td></tr>
        <tr><td></td><td class="muted">{!! $t('paid') !!}</td><td class="r">{{ $aed($b?->paid_amount) }}</td></tr>
        <tr><td></td><td><strong>{!! $t('balance') !!}</strong></td><td class="r"><strong>{{ $aed($b?->balance) }}</strong></td></tr>
        <tr><td></td><td class="muted">{!! $b?->deposit_mode === 'none' ? $t('no_deposit') : $t('deposit') !!}</td><td class="r">{{ $b?->deposit_mode === 'none' ? '—' : $aed($b?->security_deposit) }}</td></tr>
    </table>

    @if ($damage->isNotEmpty())
        <h3><span class="rule"></span>{!! $t('damage') !!}</h3>
        <table class="info">
            @foreach ($damage as $dmg)
                <tr><td class="k">{{ ['delivery' => 'At handover (out)', 'collection' => 'At return (in)'][$dmg->stage] ?? 'During the rental' }}</td>
                    <td>{{ $x(collect([$dmg->area, $dmg->description])->filter()->implode(': ')) }} <span class="muted">· {{ $dmg->created_at?->format('d M Y') }}</span></td></tr>
            @endforeach
        </table>
    @endif

    @if ($c->status === 'closed' && $c->closing)
        @php($cl = $c->closing)
        <h3><span class="rule"></span>{!! $t('closing') !!}</h3>
        <table class="info">
            @if (($cl['km_driven'] ?? null) !== null)<tr><td class="k">{!! $t('driven') !!}</td><td>{{ number_format($cl['km_driven']) }} km in {{ $cl['days'] }} day(s){{ ($cl['km_included'] ?? null) ? ' · '.number_format($cl['km_included']).' km included' : '' }}{{ ($cl['km_extra'] ?? 0) > 0 ? ' · '.number_format($cl['km_extra']).' km extra' : '' }}</td></tr>@endif
            @foreach ((array) ($cl['return_charges'] ?? []) as $rc)<tr><td class="k">{{ $rc['label'] }}</td><td>{{ $aed($rc['amount']) }}</td></tr>@endforeach
            @foreach ((array) ($cl['other_charges'] ?? []) as $oc)<tr><td class="k">{{ \Illuminate\Support\Str::limit($oc['label'], 40) }}</td><td>{{ $aed($oc['amount']) }}</td></tr>@endforeach
            <tr><td class="k">{!! $t('balance') !!}</td><td><strong>{{ $aed($cl['balance'] ?? 0) }}</strong> · deposit held {{ $aed($cl['deposit_held'] ?? 0) }}{{ ! empty($cl['deposit_refundable_on']) ? ' · refundable from '.$d($cl['deposit_refundable_on']) : '' }}</td></tr>
        </table>
    @endif

    {{-- Terms and the signatures stay together (short terms); long terms flow over pages. --}}
    <div style="page-break-inside: avoid">
    <h3><span class="rule"></span>{!! $t('terms') !!}</h3>
    @if (filled($termsEn) || filled($termsAr))
        @if (filled($termsEn))<div class="terms">{!! nl2br(e($termsEn)) !!}</div>@endif
        @if (filled($termsAr))<div class="terms" style="margin-top:8px">{{ \App\Support\Documents\PdfText::paragraph($termsAr, 110) }}</div>@endif
    @elseif ($policies->isNotEmpty())
        @foreach ($policies as $key => $label)<div class="terms"><strong>{{ $label }}.</strong> {{ $s[$key] }}</div>@endforeach
    @else
        <div class="terms muted">The rental terms of {{ \App\Support\Documents\CompanyProfile::values()['legal_name'] }} apply.</div>
    @endif

    <table class="sign">
        <tr>
            <td>
                @if ($sigData)<img class="sig" src="{{ $sigData }}" alt="">@endif
                <div class="line">{!! $t('sign_hirer') !!}@if ($c->signed_at) · {{ $c->signed_by_name }} · {!! $t('signed_on') !!} {{ $c->signed_at->format('d M Y, H:i') }}@endif</div>
            </td>
            <td>
                @if ($stamp)<img class="stamp" src="{{ $stamp }}" alt="">@endif
                <div class="line">{!! $t('sign_company') !!}{{ $c->creator ? ' · '.$c->creator->name : '' }}</div>
            </td>
        </tr>
    </table>
    </div>
@endsection

@section('foot-note')Rental agreement {{ $c->number }} · version {{ $version }}@endsection
