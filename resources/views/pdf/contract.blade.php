@extends('pdf.layout')
@php
    /**
     * 25 Nov 2026: reference Vehicle Rental Agreement layout (Shahem-style sample).
     * Page 1: Driver | Hirer, Vehicle | Charges, OUT/IN, card, three signatures.
     * Page 2+: terms + the same signature blocks. Final page: document images.
     * $c = RentalContract, $b = Booking, $version = version number being saved.
     */
    $pdfText = new \App\Support\Documents\PdfText('both');
    $t = fn (string $key) => $pdfText->t('contract.'.$key);
    $x = fn ($v) => \App\Support\Documents\PdfText::x($v);
    $aed = fn ($v) => number_format((float) $v, 2).' AED';
    $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('Y-m-d') : '—';
    $tm = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('H:i:s') : '—';
    $car = $b?->bookable;
    $cust = $c->customer;
    $main = $c->mainDriver();
    $s = \App\Models\Setting::values();
    $sheet = \App\Support\Contracts\Contracts::chargeSheet($c);
    $termsEn = filled($c->terms_en) ? $c->terms_en : ($s['contract_terms_en'] ?? null);
    $termsAr = filled($c->terms_ar) ? $c->terms_ar : ($s['contract_terms_ar'] ?? null);
    $policies = collect(['policy_fuel' => 'Fuel', 'policy_late_return' => 'Late return', 'policy_fines' => 'Fines, Salik and tolls', 'policy_cancellation' => 'Cancellation and changes'])
        ->filter(fn ($label, $key) => filled($s[$key] ?? null));
    $hirerSig = $c->getFirstMedia('signature');
    $driverSig = $c->getFirstMedia('driver_signature');
    $sigData = $hirerSig ? rescue(fn () => 'data:image/png;base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk($hirerSig->disk)->get($hirerSig->getPathRelativeToRoot())), null, false) : null;
    $driverSigData = $driverSig ? rescue(fn () => 'data:image/png;base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk($driverSig->disk)->get($driverSig->getPathRelativeToRoot())), null, false) : null;
    $stamp = \App\Support\Documents\CompanyProfile::stamp();
    $companyName = \App\Support\Documents\CompanyProfile::values()['legal_name'] ?: 'RED PRIME RENTALS';
    $outAt = $b?->delivered_at ?? $b?->start_at;
    $inAt = $b?->returned_at ?? $b?->end_at;
    $fuelOut = \App\Support\Fuel\Fuel::percentLabel($b?->delivery_fuel_8 ?? $b?->delivery_fuel);
    $fuelIn = \App\Support\Fuel\Fuel::percentLabel($b?->return_fuel_8 ?? $b?->return_fuel);
    $idOrPass = $cust?->id_number ?: $cust?->passport_number;
    $idIssue = $cust?->id_number ? $cust?->id_issued_on : $cust?->passport_issued_on;
    $idExpiry = $cust?->id_number ? $cust?->id_expires_on : $cust?->passport_expires_on;
    $licPlace = $main?->licence_place_of_issue ?: $cust?->licence_place_of_issue ?: ($main?->licence_country ?: $cust?->licence_country);
    $licNo = $main?->licence_number ?: $cust?->licence_number;
    $licIssue = $main?->licence_issued_on ?? $cust?->licence_issued_on;
    $licExpiry = $main?->licence_expires_on ?? $cust?->licence_expires_on;
    $driverName = $main?->name ?: $cust?->name;
    $driverNat = $main?->nationality ?: $cust?->nationality;
    $vehicleType = collect([$car?->brand, $car?->model ?: $car?->name, $car?->type])->filter()->unique()->implode(' ') ?: ($car?->name ?? '—');
    $vehicleModel = $car?->year ?: ($car?->model ?: '—');
    $cardLine = $c->card_last4
        ? collect([$c->card_brand, '**** **** **** '.$c->card_last4, $c->card_expires_on ? 'exp. '.$c->card_expires_on : null])->filter()->implode(' · ')
        : '—';
    $docs = $cust ? $cust->documents()->latest()->limit(12)->get() : collect();
    $docImages = collect();
    $attachmentNotes = collect();
    foreach ($docs as $doc) {
        if (! $doc->path) {
            continue;
        }
        $mime = rescue(fn () => \Illuminate\Support\Facades\Storage::disk('private')->mimeType($doc->path), null, false) ?: '';
        if (! str_starts_with($mime, 'image/')) {
            continue;
        }
        $data = rescue(fn () => 'data:'.$mime.';base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('private')->get($doc->path)), null, false);
        if ($data) {
            $label = \App\Support\Options::documentLabel($doc->type);
            $docImages->push(['label' => $label, 'src' => $data]);
        }
        if ($docImages->count() >= 12) {
            break;
        }
    }
    // Contract attachments uploaded on the contract (Add attachment) — images on the PDF; PDFs listed by name.
    foreach ($c->getMedia('attachments') as $media) {
        $mime = (string) ($media->mime_type ?: '');
        $label = 'Attachment: '.($media->file_name ?: $media->name ?: 'file');
        if (str_starts_with($mime, 'image/')) {
            $bytes = rescue(fn () => \Illuminate\Support\Facades\Storage::disk($media->disk)->get($media->getPathRelativeToRoot()), null, false);
            if ($bytes) {
                $docImages->push(['label' => $label, 'src' => 'data:'.$mime.';base64,'.base64_encode($bytes)]);
            }
        } else {
            $attachmentNotes->push($label.($mime ? ' ('.$mime.')' : ''));
        }
    }
@endphp
@section('title', 'Rental agreement '.$c->number)
@section('doc-title'){!! $t('title') !!}@endsection
@section('styles')
    .ra-grid td { width: 50%; vertical-align: top; padding: 0 4px; }
    .ra-box { border: 1px solid #d4d4d8; border-radius: 4px; padding: 8px 10px; min-height: 118px; }
    .ra-box .label { font-size: 7.2px; letter-spacing: .8px; text-transform: uppercase; color: #a8781a; font-weight: bold; margin-bottom: 5px; border-bottom: 1px solid #f0e6d4; padding-bottom: 3px; }
    .ra-row { padding: 2.5px 0; border-bottom: 1px dotted #e4e4e7; font-size: 7.6px; }
    .ra-row .k { color: #71717a; display: inline-block; min-width: 42%; }
    .ra-row .v { font-weight: 600; color: #111; }
    .ra-charges .ra-row .k { min-width: 55%; }
    .ra-outin td { width: 50%; vertical-align: top; padding: 0 4px; }
    .ra-fuel { font-size: 16px; font-weight: bold; color: #d7141a; margin-top: 6px; }
    .sig3 td { width: 33%; vertical-align: bottom; padding: 18px 6px 0; }
    .sig3 .sig-img { height: 42px; max-width: 140px; }
    .sig3 .stamp { height: 48px; }
    .sig3 .line { border-top: 1px solid #a1a1aa; padding-top: 3px; font-size: 7px; color: #71717a; }
    .terms { font-size: 7.4px; line-height: 1.45; color: #3f3f46; }
    .doc-grid td { width: 50%; vertical-align: top; padding: 6px; }
    .doc-grid img { max-width: 100%; max-height: 220px; border: 1px solid #e4e4e7; }
    .page-break { page-break-before: always; }
@endsection

@section('meta')
    <tr><td class="k">{!! $t('ra') !!}</td><td class="r"><strong>{{ $c->number }}</strong></td></tr>
    <tr><td class="k">Booking</td><td class="r">{{ $b?->reference }}</td></tr>
    @if ($c->paper_number)<tr><td class="k">Paper contract</td><td class="r">{{ $c->paper_number }}</td></tr>@endif
    <tr><td class="k">{!! $t('date') !!}</td><td class="r">{{ $c->created_at?->format('d M Y') }}</td></tr>
    <tr><td class="k">{!! $t('version') !!}</td><td class="r">v{{ $version }}</td></tr>
@endsection

@section('content')
    {{-- Page 1: agreement body --}}
    <table class="ra-grid" style="margin-top:12px">
        <tr>
            <td>
                <div class="ra-box">
                    <div class="label">{!! $t('driver_details') !!}</div>
                    <div class="ra-row"><span class="k">{!! $t('name') !!}</span><span class="v">{{ $x($driverName) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('nationality') !!}</span><span class="v">{{ $x($driverNat ?: '—') }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('driving_lic') !!}</span><span class="v">{{ $x($licNo ?: '—') }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('place_of_issue') !!}</span><span class="v">{{ $x($licPlace ?: '—') }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('date_of_issue') !!}</span><span class="v">{{ $d($licIssue) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('date_of_expiry') !!}</span><span class="v">{{ $d($licExpiry) }}</span></div>
                </div>
            </td>
            <td>
                <div class="ra-box">
                    <div class="label">{!! $t('hirer_details') !!}</div>
                    <div class="ra-row"><span class="k">{!! $t('name') !!}</span><span class="v">{{ $x($cust?->name) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('nationality') !!}</span><span class="v">{{ $x($cust?->nationality ?: '—') }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('passport_or_id') !!}</span><span class="v">{{ $x($idOrPass ?: '—') }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('date_of_issue') !!}</span><span class="v">{{ $d($idIssue) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('expiry_date') !!}</span><span class="v">{{ $d($idExpiry) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('address') !!}</span><span class="v">{{ $x($cust?->address ?: '—') }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('mobile') !!}</span><span class="v">{{ $x($cust?->phone ?: '—') }}</span></div>
                </div>
            </td>
        </tr>
    </table>

    <table class="ra-grid" style="margin-top:8px">
        <tr>
            <td>
                <div class="ra-box">
                    <div class="label">{!! $t('vehicle_details') !!}</div>
                    <div class="ra-row"><span class="k">{!! $t('plate') !!}</span><span class="v">{{ $x($car?->registration_number ?: '—') }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('vehicle_type') !!}</span><span class="v">{{ $x($vehicleType) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('colour') !!}</span><span class="v">{{ $x($car?->colour ?: '—') }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('model') !!}</span><span class="v">{{ $x($vehicleModel) }}</span></div>
                </div>
            </td>
            <td>
                <div class="ra-box ra-charges">
                    <div class="label">{!! $t('charge_details') !!}</div>
                    <div class="ra-row"><span class="k">{!! $t('base_rental') !!}</span><span class="v">{{ $aed($sheet['base']) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('discount') !!}</span><span class="v">{{ $aed($sheet['discount']) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('no_of_days') !!}</span><span class="v">{{ $sheet['days'] }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('advance_paid') !!}</span><span class="v">{{ $aed($sheet['advance']) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('traffic_fines') !!}</span><span class="v">{{ $aed($sheet['fines']) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('deposit') !!}</span><span class="v">{{ $aed($sheet['deposit']) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('add_charges') !!}</span><span class="v">{{ $aed($sheet['extras']) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('total') !!}</span><span class="v">{{ $aed($sheet['total']) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('paid') !!}</span><span class="v">{{ $aed($sheet['paid']) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('balance') !!}</span><span class="v"><strong>{{ $aed($sheet['balance']) }}</strong></span></div>
                </div>
            </td>
        </tr>
    </table>

    <table class="ra-outin" style="margin-top:8px">
        <tr>
            <td>
                <div class="ra-box">
                    <div class="label">{!! $t('out') !!}</div>
                    <div class="ra-row"><span class="k">{!! $t('date') !!}</span><span class="v">{{ $d($outAt) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('time') !!}</span><span class="v">{{ $tm($outAt) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('km') !!}</span><span class="v">{{ $b?->delivery_km !== null ? number_format($b->delivery_km) : '—' }}</span></div>
                    <div class="label" style="margin-top:8px">{!! $t('check_out') !!}</div>
                    <div class="ra-fuel">{{ $fuelOut }}</div>
                </div>
            </td>
            <td>
                <div class="ra-box">
                    <div class="label">{!! $t('in') !!}</div>
                    <div class="ra-row"><span class="k">{!! $t('date') !!}</span><span class="v">{{ $d($inAt) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('time') !!}</span><span class="v">{{ $tm($inAt) }}</span></div>
                    <div class="ra-row"><span class="k">{!! $t('km') !!}</span><span class="v">{{ $b?->return_km !== null ? number_format($b->return_km) : '—' }}</span></div>
                    <div class="label" style="margin-top:8px">{!! $t('check_in') !!}</div>
                    <div class="ra-fuel">{{ $fuelIn }}</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="ra-box" style="margin-top:8px; min-height:0">
        <div class="ra-row"><span class="k">{!! $t('credit_card') !!}</span><span class="v">{{ $x($cardLine) }}</span></div>
    </div>

    @include('pdf.partials.contract-signatures', [
        't' => $t, 'x' => $x, 'sigData' => $sigData, 'driverSigData' => $driverSigData,
        'stamp' => $stamp, 'c' => $c, 'companyName' => $companyName,
    ])

    {{-- Page 2: terms (default = reference-style T&Cs from Contract settings) --}}
    <div class="page-break">
        <h3><span class="rule"></span>{!! $t('terms') !!}</h3>
        @if (filled($termsEn) || filled($termsAr))
            @if (filled($termsEn))<div class="terms">{!! nl2br(e($termsEn)) !!}</div>@endif
            @if (filled($termsAr))<div class="terms" style="margin-top:8px">{{ \App\Support\Documents\PdfText::paragraph($termsAr, 110) }}</div>@endif
        @elseif ($policies->isNotEmpty())
            @foreach ($policies as $key => $label)<div class="terms"><strong>{{ $label }}.</strong> {{ $s[$key] }}</div>@endforeach
        @else
            <div class="terms muted">The rental terms of {{ $companyName }} apply.</div>
        @endif

        @include('pdf.partials.contract-signatures', [
            't' => $t, 'x' => $x, 'sigData' => $sigData, 'driverSigData' => $driverSigData,
            'stamp' => $stamp, 'c' => $c, 'companyName' => $companyName,
        ])
    </div>

    {{-- Page 3: customer document images + contract attachments --}}
    <div class="page-break">
        <h3><span class="rule"></span>{!! $t('document_images') !!}</h3>
        @if ($docImages->isEmpty() && $attachmentNotes->isEmpty())
            <div class="terms muted">No document images or contract attachments yet.</div>
        @else
            @if ($docImages->isNotEmpty())
                <table class="doc-grid">
                    @foreach ($docImages->chunk(2) as $row)
                        <tr>
                            @foreach ($row as $img)
                                <td>
                                    <div class="muted" style="margin-bottom:4px">{{ $img['label'] }}</div>
                                    <img src="{{ $img['src'] }}" alt="">
                                </td>
                            @endforeach
                            @if ($row->count() === 1)<td></td>@endif
                        </tr>
                    @endforeach
                </table>
            @endif
            @if ($attachmentNotes->isNotEmpty())
                <div class="terms" style="margin-top:10px">
                    <strong>Other attachments (file kept with the contract; not an image for this page):</strong>
                    <ul style="margin:4px 0 0;padding-left:16px">
                        @foreach ($attachmentNotes as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    </div>
@endsection

@section('foot-note')Rental agreement {{ $c->number }} · version {{ $version }}@endsection
