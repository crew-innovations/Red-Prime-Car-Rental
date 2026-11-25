@extends('pdf.layout')
@php
    /**
     * 25 Nov 2026: reference Vehicle Rental Agreement (Shahem-style sample, RED PRIME brand).
     * Exact 3 pages: (1) Driver|Hirer, Vehicle|Charges, OUT/IN + car diagrams, Conditions EN|AR, card, signatures;
     * (2) Terms EN|UR side-by-side + signatures; (3) Document images / attachments.
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
    $termsUr = filled($c->terms_ur) ? $c->terms_ur : ($s['contract_terms_ur'] ?? null);
    $policies = collect(['policy_fuel' => 'Fuel', 'policy_late_return' => 'Late return', 'policy_fines' => 'Fines, Salik and tolls', 'policy_cancellation' => 'Cancellation and changes'])
        ->filter(fn ($label, $key) => filled($s[$key] ?? null));
    /** First N numbered clauses for the short Conditions box on page 1. */
    $firstClauses = function (?string $text, int $n = 3): string {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (preg_match_all('/(?:^|\n)\s*((?:\d+|[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+|[۱۲۳۴۵۶۷۸۹۰]+)[\-\.\)]\s*.+?)(?=(?:\n\s*(?:\d+|[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+|[۱۲۳۴۵۶۷۸۹۰]+)[\-\.\)])|\z)/su', $text, $m)) {
            return trim(implode("\n\n", array_slice($m[1], 0, $n)));
        }
        $paras = preg_split('/\n{2,}/', $text) ?: [];

        return trim(implode("\n\n", array_slice(array_values(array_filter(array_map('trim', $paras))), 0, $n)));
    };
    $condEn = $firstClauses($termsEn, 3);
    $condAr = $firstClauses($termsAr, 3);
    if ($condEn === '' && $policies->isNotEmpty()) {
        $condEn = $policies->map(fn ($label, $key) => $label.'. '.$s[$key])->take(3)->implode("\n\n");
    }
    $outNotes = $b?->delivery_notes;
    $inNotes = $b?->return_notes;
    $inCondition = $b?->return_condition ? (\App\Models\Booking::CONDITIONS[$b->return_condition] ?? $b->return_condition) : null;
    $outPhotos = collect();
    $inPhotos = collect();
    $pushPhoto = function ($media, $bag) {
        $name = $media->file_name ?: $media->name;
        if (! \App\Support\MediaImage::isImage($media->mime_type, $name, $media->getPathRelativeToRoot())) {
            return;
        }
        $mime = \App\Support\MediaImage::mime($media->mime_type, $name);
        $bytes = rescue(fn () => \Illuminate\Support\Facades\Storage::disk($media->disk)->get($media->getPathRelativeToRoot()), null, false);
        if ($bytes) {
            $bag->push('data:'.$mime.';base64,'.base64_encode($bytes));
        }
    };
    if ($b) {
        foreach ($b->getMedia('delivery')->take(4) as $media) {
            $pushPhoto($media, $outPhotos);
        }
        foreach ($b->getMedia('return')->take(4) as $media) {
            $pushPhoto($media, $inPhotos);
        }
    }
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
    $fuelOutEighth = \App\Support\Fuel\Fuel::eighth($b?->delivery_fuel_8 ?? $b?->delivery_fuel);
    $fuelInEighth = \App\Support\Fuel\Fuel::eighth($b?->return_fuel_8 ?? $b?->return_fuel);
    $fuelBar = fn (?int $eighth) => max(0, min(100, $eighth === null ? 0 : (int) round($eighth / 8 * 100)));
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
        $mime = rescue(fn () => \Illuminate\Support\Facades\Storage::disk('private')->mimeType($doc->path), null, false) ?: ($doc->mime ?? '');
        if (! \App\Support\MediaImage::isImage($mime, $doc->original_name, $doc->path)) {
            continue;
        }
        $mime = \App\Support\MediaImage::mime($mime, $doc->original_name, $doc->path);
        $data = rescue(fn () => 'data:'.$mime.';base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('private')->get($doc->path)), null, false);
        if ($data) {
            $docImages->push(['label' => \App\Support\Options::documentLabel($doc->type), 'src' => $data]);
        }
        if ($docImages->count() >= 12) {
            break;
        }
    }
    foreach ($c->getMedia('attachments') as $media) {
        $name = $media->file_name ?: $media->name ?: 'file';
        $label = 'Attachment: '.$name;
        if (\App\Support\MediaImage::isImage($media->mime_type, $name, $media->getPathRelativeToRoot())) {
            $mime = \App\Support\MediaImage::mime($media->mime_type, $name);
            $bytes = rescue(fn () => \Illuminate\Support\Facades\Storage::disk($media->disk)->get($media->getPathRelativeToRoot()), null, false);
            if ($bytes) {
                $docImages->push(['label' => $label, 'src' => 'data:'.$mime.';base64,'.base64_encode($bytes)]);
            }
        } else {
            $attachmentNotes->push($label.($media->mime_type ? ' ('.$media->mime_type.')' : ''));
        }
    }
    $carPng = 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('views/pdf/partials/car-top.png')));
@endphp
@section('title', 'Rental agreement '.$c->number)
@section('doc-title'){!! $t('title') !!}@endsection
@section('styles')
    .gold { color: #a8781a; }
    .doc-title { font-size: 15px; margin: 0 0 2px; }
    .logo { height: 30px; }
    .bar { margin: -30px -36px 8px; }
    .co-name { margin-top: 2px; font-size: 8.5px; }
    .co { font-size: 6.8px; line-height: 1.25; }
    .ra-grid { margin-top: 4px; }
    .ra-grid td { width: 50%; vertical-align: top; padding: 0 3px; }
    .ra-box { border: 1.5px solid #d4a62a; border-radius: 2px; padding: 0; min-height: 0; overflow: hidden; }
    .ra-box .label { font-size: 7px; letter-spacing: .3px; text-transform: uppercase; color: #111; font-weight: bold; margin: 0; padding: 2px 6px; background: #e8d48b; border-bottom: 1px solid #d4a62a; }
    .ra-box .body { padding: 2px 6px 3px; }
    .ra-row { padding: 0; border-bottom: 1px solid #f3ead2; font-size: 6.7px; line-height: 1.2; }
    .ra-row .k { color: #52525b; display: inline; }
    .ra-row .k .ar-sub { font-size: 6px; white-space: normal; }
    .ra-row .v { font-weight: 600; color: #111; }
    .ra-charges .ra-row .k { min-width: 55%; }
    .ra-outin-mini { width: 100%; margin-top: 4px; }
    .ra-outin-mini td { width: 50%; vertical-align: top; padding: 0 2px; }
    .ra-out-box, .ra-in-box { border: 1px solid #d4a62a; background: #faf6e8; padding: 4px 6px; font-size: 7px; }
    .ra-out-box .ttl, .ra-in-box .ttl { font-weight: bold; color: #a8781a; font-size: 7.2px; margin-bottom: 2px; }
    .ra-diagrams td { width: 50%; vertical-align: top; text-align: center; padding: 2px 6px; }
    .ra-diagram-title { font-size: 8px; font-weight: bold; color: #111; margin-bottom: 2px; }
    .car-top { width: 168px; height: auto; }
    .ra-fuel-wrap { margin: 0 auto 4px; width: 88%; text-align: left; }
    .ra-fuel-track { height: 8px; background: #e4e4e7; border: 1px solid #a1a1aa; border-radius: 2px; overflow: hidden; }
    .ra-fuel-fill { height: 8px; background: #d7141a; }
    .ra-fuel-lbl { font-size: 7px; font-weight: bold; color: #d7141a; margin-top: 2px; }
    .ra-car-photos img { width: 46%; max-height: 48px; margin: 1%; border: 1px solid #e4e4e7; }
    .cond-grid td { width: 50%; vertical-align: top; padding: 0; }
    .cond-box { border: 1.5px solid #d4a62a; min-height: 0; }
    .cond-box .label { font-size: 7.4px; font-weight: bold; padding: 3px 8px; background: #e8d48b; border-bottom: 1px solid #d4a62a; }
    .cond-box .body { padding: 6px 8px; font-size: 7px; line-height: 1.35; color: #3f3f46; }
    .cond-ar { direction: rtl; text-align: right; font-family: DejaVu Sans, sans-serif; }
    .card-row { border: 1px solid #d4a62a; padding: 5px 8px; margin-top: 6px; font-size: 7.4px; }
    .sig3 td { width: 33%; vertical-align: bottom; padding: 8px 6px 0; }
    .sig3 .sig-img { height: 36px; max-width: 130px; }
    .sig3 .stamp { height: 42px; }
    .sig3 .line { border-top: 1px solid #a1a1aa; padding-top: 3px; font-size: 6.8px; color: #71717a; }
    .terms-parallel { width: 100%; table-layout: fixed; }
    .terms-parallel td { width: 50%; vertical-align: top; padding: 0 6px; }
    .terms-parallel .col-en { border-right: 1px solid #e8d48b; padding-right: 8px; }
    .terms-parallel .col-ur { direction: rtl; text-align: right; font-family: DejaVu Sans, sans-serif; padding-left: 8px; }
    .terms-parallel .col-title { font-size: 9px; font-weight: bold; color: #a8781a; margin-bottom: 6px; border-bottom: 1.5px solid #d4a62a; padding-bottom: 3px; }
    .terms { font-size: 6.6px; line-height: 1.38; color: #3f3f46; }
    .terms-ur { direction: rtl; text-align: right; font-family: DejaVu Sans, sans-serif; }
    .doc-grid td { width: 50%; vertical-align: top; padding: 6px; }
    .doc-grid img { max-width: 100%; max-height: 220px; border: 1px solid #e4e4e7; }
    .page-break { page-break-before: always; }
    .ra-section-title { font-size: 8px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; color: #a8781a; margin: 8px 2px 3px; }
@endsection

@section('meta')
    <tr><td class="k">{!! $t('ra') !!}</td><td class="r"><strong>{{ $c->number }}</strong></td></tr>
    <tr><td class="k">Booking</td><td class="r">{{ $b?->reference }}</td></tr>
    @if ($c->paper_number)<tr><td class="k">Paper contract</td><td class="r">{{ $c->paper_number }}</td></tr>@endif
    <tr><td class="k">{!! $t('date') !!}</td><td class="r">{{ $c->created_at?->format('d M Y') }}</td></tr>
    <tr><td class="k">{!! $t('version') !!}</td><td class="r">v{{ $version }}</td></tr>
@endsection

@section('content')
    {{-- ========== PAGE 1 ========== --}}
    <table class="ra-grid" style="margin-top:8px">
        <tr>
            <td>
                <div class="ra-box">
                    <div class="label">{!! $t('driver_details') !!}</div>
                    <div class="body">
                        <div class="ra-row"><span class="k">{!! $t('name') !!}</span><span class="v">{{ $x($driverName) }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('nationality') !!}</span><span class="v">{{ $x($driverNat ?: '—') }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('driving_lic') !!}</span><span class="v">{{ $x($licNo ?: '—') }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('place_of_issue') !!}</span><span class="v">{{ $x($licPlace ?: '—') }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('date_of_issue') !!}</span><span class="v">{{ $d($licIssue) }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('date_of_expiry') !!}</span><span class="v">{{ $d($licExpiry) }}</span></div>
                    </div>
                </div>
            </td>
            <td>
                <div class="ra-box">
                    <div class="label">{!! $t('hirer_details') !!}</div>
                    <div class="body">
                        <div class="ra-row"><span class="k">{!! $t('name') !!}</span><span class="v">{{ $x($cust?->name) }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('nationality') !!}</span><span class="v">{{ $x($cust?->nationality ?: '—') }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('passport_or_id') !!}</span><span class="v">{{ $x($idOrPass ?: '—') }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('date_of_issue') !!}</span><span class="v">{{ $d($idIssue) }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('expiry_date') !!}</span><span class="v">{{ $d($idExpiry) }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('address') !!}</span><span class="v">{{ $x($cust?->address ?: '—') }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('mobile') !!}</span><span class="v">{{ $x($cust?->phone ?: '—') }}</span></div>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <table class="ra-grid" style="margin-top:6px">
        <tr>
            <td>
                <div class="ra-box">
                    <div class="label">{!! $t('vehicle_details') !!}</div>
                    <div class="body">
                        <div class="ra-row"><span class="k">{!! $t('plate') !!}</span><span class="v">{{ $x($car?->registration_number ?: '—') }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('vehicle_type') !!}</span><span class="v">{{ $x($vehicleType) }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('colour') !!}</span><span class="v">{{ $x($car?->colour ?: '—') }}</span></div>
                        <div class="ra-row"><span class="k">{!! $t('model') !!}</span><span class="v">{{ $x($vehicleModel) }}</span></div>
                        <table class="ra-outin-mini">
                            <tr>
                                <td>
                                    <div class="ra-out-box">
                                        <div class="ttl">{!! $t('out') !!}</div>
                                        <div>{!! $t('date') !!}: {{ $d($outAt) }}</div>
                                        <div>{!! $t('time') !!}: {{ $tm($outAt) }}</div>
                                        <div>{!! $t('km') !!}: {{ $b?->delivery_km !== null ? number_format($b->delivery_km) : '—' }}</div>
                                    </div>
                                </td>
                                <td>
                                    <div class="ra-in-box">
                                        <div class="ttl">{!! $t('in') !!}</div>
                                        <div>{!! $t('date') !!}: {{ $d($inAt) }}</div>
                                        <div>{!! $t('time') !!}: {{ $tm($inAt) }}</div>
                                        <div>{!! $t('km') !!}: {{ $b?->return_km !== null ? number_format($b->return_km) : '—' }}</div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </td>
            <td>
                <div class="ra-box ra-charges">
                    <div class="label">{!! $t('charge_details') !!}</div>
                    <div class="body">
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
                </div>
            </td>
        </tr>
    </table>

    <div class="ra-section-title">{!! $t('car_structure') !!} · {!! $t('before') !!} / {!! $t('after') !!}</div>
    <table class="ra-diagrams">
        <tr>
            <td>
                <div class="ra-diagram-title">{!! $t('check_out') !!}</div>
                <div class="ra-fuel-wrap">
                    <div class="ra-fuel-track"><div class="ra-fuel-fill" style="width:{{ $fuelBar($fuelOutEighth) }}%"></div></div>
                    <div class="ra-fuel-lbl">{{ $fuelOut }}</div>
                </div>
                <img class="car-top" src="{{ $carPng }}" alt="Vehicle check out">
                @if (filled($outNotes))
                    <div class="terms" style="text-align:left;margin-top:2px"><strong>{!! $t('notes') !!}:</strong> {{ $x($outNotes) }}</div>
                @endif
                @if ($outPhotos->isNotEmpty())
                    <div class="ra-car-photos">
                        @foreach ($outPhotos as $src)
                            <img src="{{ $src }}" alt="">
                        @endforeach
                    </div>
                @endif
            </td>
            <td>
                <div class="ra-diagram-title">{!! $t('check_in') !!}</div>
                <div class="ra-fuel-wrap">
                    <div class="ra-fuel-track"><div class="ra-fuel-fill" style="width:{{ $fuelBar($fuelInEighth) }}%"></div></div>
                    <div class="ra-fuel-lbl">{{ $fuelIn }}</div>
                </div>
                <img class="car-top" src="{{ $carPng }}" alt="Vehicle check in">
                @if ($inCondition)
                    <div class="terms" style="text-align:left;margin-top:2px"><strong>{!! $t('condition') !!}:</strong> {{ $x($inCondition) }}</div>
                @endif
                @if (filled($inNotes))
                    <div class="terms" style="text-align:left"><strong>{!! $t('notes') !!}:</strong> {{ $x($inNotes) }}</div>
                @endif
                @if ($inPhotos->isNotEmpty())
                    <div class="ra-car-photos">
                        @foreach ($inPhotos as $src)
                            <img src="{{ $src }}" alt="">
                        @endforeach
                    </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Page 2: conditions, card, signatures (page 1 is the tables + car diagrams). --}}
    <div class="page-break"></div>
    <table class="cond-grid" style="margin-top:4px">
        <tr>
            <td style="padding-right:3px">
                <div class="cond-box">
                    <div class="label">Conditions</div>
                    <div class="body">
                        @if ($condEn !== '')
                            {!! nl2br(e($condEn)) !!}
                        @else
                            The rental terms of {{ $companyName }} on page 2 apply.
                        @endif
                    </div>
                </div>
            </td>
            <td style="padding-left:3px">
                <div class="cond-box">
                    <div class="label">{!! $t('conditions') !!}</div>
                    <div class="body cond-ar">
                        @if ($condAr !== '')
                            {{ \App\Support\Documents\PdfText::paragraph($condAr, 90) }}
                        @else
                            {{ \App\Support\Documents\PdfText::paragraph('تُطبَّق شروط الشركة في الصفحة الثانية.', 90) }}
                        @endif
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <div class="card-row">
        <span class="k">{!! $t('credit_card') !!}:</span>
        <strong style="margin-left:8px">{{ $x($cardLine) }}</strong>
    </div>

    @include('pdf.partials.contract-signatures', [
        't' => $t, 'x' => $x, 'sigData' => $sigData, 'driverSigData' => $driverSigData,
        'stamp' => $stamp, 'c' => $c, 'companyName' => $companyName,
    ])

    {{-- Page 3: Terms EN | UR side by side. Signatures stay on page 2 so they do not open a blank page. --}}
    <div class="page-break">
        <table class="terms-parallel">
            <tr>
                <td class="col-en">
                    <div class="col-title">{!! $t('terms') !!} · English</div>
                    @if (filled($termsEn))
                        <div class="terms">{!! nl2br(e($termsEn)) !!}</div>
                    @elseif ($policies->isNotEmpty())
                        @foreach ($policies as $key => $label)
                            <div class="terms"><strong>{{ $label }}.</strong> {{ $s[$key] }}</div>
                        @endforeach
                    @else
                        <div class="terms muted">The rental terms of {{ $companyName }} apply.</div>
                    @endif
                </td>
                <td class="col-ur">
                    <div class="col-title">شرائط و ضوابط · Urdu</div>
                    @if (filled($termsUr))
                        <div class="terms terms-ur">{!! nl2br(e($termsUr)) !!}</div>
                    @else
                        <div class="terms terms-ur muted">اردو شرائط Contract settings میں شامل کریں۔</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- Page 4: document images / attachments --}}
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
                    <strong>Other attachments (kept with the contract; not an image for this page):</strong>
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
