@extends('pdf.layout')

@php
    /**
     * Quotation PDF (redesign 4 Nov 2026, brief 12.2). English, Arabic or both (quotations.pdf_language;
     * empty = from the customer's language). dompdf has no right-to-left tables, so in Arabic the
     * columns are put in mirrored order here and the Arabic text is shaped (App\Support\Documents\ArabicText).
     */
    $pdfText = new \App\Support\Documents\PdfText($q->pdf_language ?: \App\Support\Documents\PdfText::forCustomer($q->customer?->language));
    $rtl = $pdfText->rtl();
    $t = fn (string $key, array $r = []) => $pdfText->t($key, $r);
    $x = fn (?string $v) => \App\Support\Documents\PdfText::x($v);
    $p = fn (string $key, array $r = []) => \App\Support\Documents\PdfText::x($pdfText->plain($key, $r)); // one language, shaped
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $pct = fn ($v) => \App\Support\Pricing\Discounts::percentText((float) $v);
    // A row of cells, mirrored in Arabic.
    $row = fn (array $cells, string $attrs = '') => '<tr'.$attrs.'>'.implode('', $rtl ? array_reverse($cells) : $cells).'</tr>';
    $k = fn ($label, string $style = '') => '<td class="k"'.($style ? ' style="'.$style.'"' : '').'>'.$label.'</td>';
    $v = fn ($value, string $class = '') => '<td class="v '.$class.'">'.$value.'</td>';

    $item = $q->bookable;
    $service = $q->service_type;
    $isCar = $service === \App\Enums\ServiceType::Car;
    $isYacht = $service === \App\Enums\ServiceType::Yacht;
    $isTransfer = $service === \App\Enums\ServiceType::Transfer;
    $priced = \App\Support\Pricing\PriceLines::for($q);
    $s = \App\Models\Setting::values();
    $photo = \App\Support\Documents\Pdf::photo($q->packageDeparture?->package ?? $item);
    $minutes = $q->start_at && $q->end_at ? max(1, (int) $q->start_at->diffInMinutes($q->end_at)) : null;
    $duration = $minutes === null ? null : ($minutes >= 1440 ? ceil($minutes / 1440).' '.$pdfText->plain('days') : ceil($minutes / 60).' '.$pdfText->plain('hours'));
    $statusKey = match (true) {
        $q->status === \App\Enums\QuotationStatus::Accepted => 'accepted',
        $q->status === \App\Enums\QuotationStatus::Rejected => 'rejected',
        $q->status === \App\Enums\QuotationStatus::Expired || ($q->valid_until && $q->valid_until->lt(today())) => 'expired',
        $q->status === \App\Enums\QuotationStatus::Negotiation => 'negotiation',
        default => $q->sent_at ? 'sent' : 'draft',
    };
    $statusPill = ['accepted' => 'ok', 'rejected' => 'bad', 'expired' => 'bad', 'negotiation' => 'warn', 'sent' => 'info', 'draft' => 'info'][$statusKey];
    $agent = $q->creator;
    $bank = \App\Support\Documents\CompanyProfile::bank();
    $stamp = \App\Support\Documents\CompanyProfile::stamp();
    $qr = $q->public_token && ! in_array($statusKey, ['rejected', 'expired'], true) ? \App\Support\Documents\Pdf::qr($q->publicUrl()) : null;
    $advance = (float) $q->advance_required;
    $balance = round((float) $q->total - $advance, 2);
    $balanceDue = \App\Support\Pricing\Advance::balanceDue($q);
    $specs = match (true) {
        $isCar => collect([$item?->year, $item?->type ? ucfirst((string) $item->type) : null, $item?->seats ? $item->seats.' seats' : null, $item?->transmission ? ucfirst((string) $item->transmission) : null, $q->drive_mode?->getLabel()])->filter()->implode('  ·  '),
        $isYacht => collect([$item?->length_ft ? $item->length_ft.' ft' : null, $item?->max_guests ? 'up to '.$item->max_guests.' guests' : null, $q->crew_mode === 'bareboat' ? 'Without crew' : 'With captain & crew'])->filter()->implode('  ·  '),
        $isTransfer => collect([$q->vehicleCategory?->name, $q->guests ? $q->guests.' passengers' : null])->filter()->implode('  ·  '),
        default => collect([$q->guests ? $q->guests.' guests' : null])->filter()->implode('  ·  '),
    };
    $termsText = trim(($q->terms ?? '')."\n".(($isCar || $isYacht) ? ($item?->terms ?? '') : '')."\n".($s['policy_cancellation'] ?? '')."\n".($s['policy_late_return'] ?? ''));
    $termsUrl = rescue(fn () => route('site.terms'), null, false);

    // Details of the service.
    $info = [];
    if ($q->start_at) $info[] = [$isCar ? $t('pickup') : $t('start'), e($q->start_at->format('D d M Y, H:i'))];
    if ($q->end_at && ! $isTransfer) $info[] = [$isCar ? $t('return') : $t('end'), e($q->end_at->format('D d M Y, H:i'))];
    if ($duration && ! $isTransfer) $info[] = [$t('duration'), $x($duration)];
    if ($q->guests && ! $isCar && ! $isTransfer) $info[] = [$t('guests'), e($q->guests)];
    if ($isCar) {
        $info[] = [$t('delivery'), $q->delivery_method === 'delivery' ? $p('delivered_to_you').($q->pickup_location ? ': '.$x($q->pickup_location) : '') : ($q->pickup_location ? $x($q->pickup_location) : $p('collect_office'))];
        $info[] = [$t('collection'), $q->return_method === 'collection' ? $p('we_collect') : $p('return_office')];
    } elseif ($isTransfer) {
        $route = collect([$q->pickup_location, ...collect((array) $q->stops)->map(fn ($st) => is_array($st) ? ($st['address'] ?? null) : $st)->all(), $q->dropoff_location])->filter()->implode(' → ');
        $info[] = [$t('route'), $x($route).($q->route_km ? ' <span class="muted">('.rtrim(rtrim(number_format((float) $q->route_km, 1), '0'), '.').' km)</span>' : '')];
    } elseif ($q->pickup_location) {
        $info[] = [$t('pickup'), $x($q->pickup_location)];
    }

    // Included and not included.
    $incl = [];
    if ($isCar) {
        $incl[] = [$t('mileage'), $x($item?->daily_km_limit
            ? $pdfText->plain('km_included', ['km' => number_format($item->daily_km_limit)]).($item->extra_km_charge ? ($rtl ? '، ' : ', ').$pdfText->plain('extra_km', ['amount' => $aed($item->extra_km_charge)]) : '')
            : $pdfText->plain('no_km_limit'))];
        if ($item?->insurance_type) $incl[] = [$t('insurance'), e(ucfirst(str_replace('_', ' ', (string) $item->insurance_type)))];
    }
    if (! empty($s['policy_fuel'])) $incl[] = [$t('fuel'), \App\Support\Documents\PdfText::paragraph($s['policy_fuel'], 70)];
    if (! empty($s['policy_fines'])) $incl[] = [$t('tolls_fines'), \App\Support\Documents\PdfText::paragraph($s['policy_fines'], 70)];
    if (! $isCar && $item && ! empty($item->included_services)) $incl[] = ['✓', $x(implode(', ', (array) $item->included_services))];
    if (! $isCar && $item && ! empty($item->excluded_services)) $incl[] = ['✗', $x(implode(', ', (array) $item->excluded_services))];
@endphp

@section('title', 'Quotation '.$q->number)
@section('doc-title'){!! $t('quotation') !!}@endsection

@section('styles')
    .svc-photo { width: 100%; height: 104px; border-radius: 7px; }
    .pay td { padding: 2.5px 0; }
    .pay .big { font-size: 11px; font-weight: bold; color: #111; }
    .accept { border: 1.5px solid #d7141a; border-radius: 8px; padding: 9px 12px; }
    .btn { display: inline-block; background: #d7141a; color: #fff; text-decoration: none; font-weight: bold; padding: 5px 14px; border-radius: 14px; font-size: 8.2px; }
    .incl td { padding: 2.5px 0; vertical-align: top; }
    .incl .k { width: 30%; color: #71717a; }
    .stamp { height: 54px; }
    .rtl td.k, .rtl td.v, .rtl .label, .rtl .strong, .rtl h3, .rtl .box, .rtl .accept, .rtl .box-soft .label { text-align: right; }
    .rtl .lines th { text-align: right; }
    .rtl .doc-meta .k { padding-right: 0; padding-left: 12px; }
    .rtl .lines th.l, .rtl .lines td.l { text-align: left; }
@endsection

@section('meta')
    {!! $row([$k($t('number')), $v('<strong>'.e($q->number).'</strong>', 'r')]) !!}
    {!! $row([$k($t('date')), $v(e(($q->sent_at ?? $q->created_at)->format('d M Y')), 'r')]) !!}
    @if ($q->valid_until){!! $row([$k($t('valid_until')), $v(e($q->valid_until->format('d M Y')), 'r')]) !!}@endif
    {!! $row([$k($t('status')), $v('<span class="pill pill-'.$statusPill.'">'.$t('statuses.'.$statusKey).'</span>', 'r')]) !!}
@endsection

@section('content')
    {{-- 2. Prepared for / by --}}
    @php
        $for = '<td style="padding-'.($rtl ? 'left' : 'right').':7px"><div class="box"><div class="label">'.$t('prepared_for').'</div>'
            .'<div class="strong">'.$x($q->customerName()).'</div>'
            .($q->customer?->company_name && $q->customer->company_name !== $q->customerName() ? '<div>'.$x($q->customer->company_name).'</div>' : '')
            .'<div class="muted">'.e(collect([$q->customerPhone(), $q->customerEmail()])->filter()->implode('  ·  ')).'</div></div></td>';
        $by = '<td style="padding-'.($rtl ? 'right' : 'left').':7px"><div class="box"><div class="label">'.$t('prepared_by').'</div>'
            .'<div class="strong">'.$x($agent?->name ?? $s['company_name'] ?? 'RED PRIME RENTALS').'</div>'
            .'<div class="muted">'.e(collect([$agent?->phone ?: ($s['company_phone'] ?? null), $agent?->email ?: ($s['company_email'] ?? null)])->filter()->implode('  ·  ')).'</div></div></td>';
    @endphp
    <table class="parties" style="margin-top:14px">{!! $row([$for, $by]) !!}</table>

    {{-- 3. Service --}}
    @php
        $infoRows = collect($info)->map(fn ($r) => $row([$k($r[0]), $v($r[1])]))->implode('');
        $svc = '<td style="vertical-align:top"><div class="label">'.e($service?->getLabel()).'</div>'
            .'<div class="strong" style="font-size:12px">'.$x($q->packageDeparture?->package?->name ?? $item?->name ?? $q->transferService?->name ?? $service?->getLabel()).'</div>'
            .($specs ? '<div class="muted" style="margin-bottom:4px'.($rtl ? ';text-align:right' : '').'">'.e($specs).'</div>' : '')
            .'<table class="info">'.$infoRows.'</table></td>';
        $pic = $photo ? '<td style="width:36%;padding-'.($rtl ? 'left' : 'right').':12px;vertical-align:top"><img class="svc-photo" src="'.$photo.'" alt=""></td>' : null;
    @endphp
    <table style="margin-top:12px">{!! $row(array_filter([$pic, $svc])) !!}</table>

    {{-- 4. Price table: item, list price, discount %, discount, net --}}
    @php
        $head = ['<th>'.$t('item').'</th>'];
        if ($priced['has_discount']) {
            $head[] = '<th class="r l" style="width:14%">'.$t('list_price').'</th>';
            $head[] = '<th class="r l" style="width:10%">'.$t('discount').'</th>';
            $head[] = '<th class="r l" style="width:13%">'.$t('discount_amount').'</th>';
        }
        $head[] = '<th class="r l" style="width:16%">'.$t('net').'</th>';
    @endphp
    <table class="lines">
        <thead>{!! $row($head) !!}</thead>
        <tbody>
            @foreach ($priced['lines'] as $line)
                @php
                    $lineLabel = $loop->first ? $t('line_'.($isCar ? 'rental' : ($isYacht ? 'charter' : ($isTransfer ? 'transfer' : 'price')))) : $x($line['label']);
                    $cells = ['<td'.($rtl ? ' style="text-align:right"' : '').'>'.$lineLabel.($line['waived'] ? ' <span class="pill pill-ok">Waived</span>' : '')
                        .($line['detail'] ? '<div class="detail">'.e($line['detail']).'</div>' : '').'</td>'];
                    if ($priced['has_discount']) {
                        $cells[] = '<td class="r l">'.number_format($line['list'], 2).'</td>';
                        $cells[] = '<td class="r l off">'.($line['percent'] ? $pct($line['percent']) : '').'</td>';
                        $cells[] = '<td class="r l off">'.($line['off'] > 0 ? '− '.number_format($line['off'], 2) : '').'</td>';
                    }
                    $cells[] = '<td class="r l">'.number_format($line['net'], 2).'</td>';
                @endphp
                {!! $row($cells) !!}
            @endforeach
        </tbody>
    </table>

    @php
        $sum = function ($label, $amount, string $attrs = '', string $amountStyle = '') use ($row, $rtl) {
            return $row(['<td style="width:56%"></td>', '<td class="muted"'.($rtl ? ' style="text-align:right"' : '').'>'.$label.'</td>', '<td class="r"'.($amountStyle ? ' style="'.$amountStyle.'"' : '').'>'.$amount.'</td>'], $attrs);
        };
    @endphp
    <table class="totals">
        {!! $sum($t('subtotal'), $aed($priced['gross'])) !!}
        @if ($priced['overall'] > 0)
            {!! $sum($t('discount_total').($priced['overall_percent'] ? ' ('.$pct($priced['overall_percent']).')' : ''), '− '.$aed($priced['overall']), '', 'color:#b91c1c') !!}
        @endif
        @if ((float) $q->tax_amount > 0)
            {!! $sum($t('vat').' '.$pct($q->tax_rate).($q->tax_inclusive ? ' ('.$t('vat_included').')' : ''), $aed($q->tax_amount)) !!}
        @endif
        {!! $sum('<span style="color:#111">'.$t('total').'</span>', $aed($q->total), ' class="grand"') !!}
    </table>

    {{-- 5. Payment --}}
    @if ($advance > 0 || (float) $q->security_deposit > 0)
        <div class="box-soft" style="margin-top:10px">
            <div class="label">{!! $t('payment') !!}</div>
            <table class="pay">
                @if ($advance > 0)
                    {!! $row([$v($t('advance').($q->advance_percent !== null ? ' · '.$pct($q->advance_percent) : '')), '<td class="r big">'.$aed($advance).'</td>']) !!}
                    @if ($balance > 0.005)
                        {!! $row([$v($t('balance').($balanceDue ? ' · '.$t('due').' '.e($balanceDue->format('d M Y')) : '')), '<td class="r">'.$aed($balance).'</td>']) !!}
                    @endif
                @endif
                @if ((float) $q->security_deposit > 0)
                    {!! $row([$v($t('deposit').'<div class="muted" style="font-size:7.2px">'.$t('deposit_note').'</div>'), '<td class="r">'.$aed($q->security_deposit).'</td>']) !!}
                @endif
            </table>
        </div>
    @endif

    {{-- 6. Included / not included --}}
    @if ($incl)
        <h3><span class="rule"></span>{!! $t('included') !!}</h3>
        <table class="incl">
            @foreach ($incl as [$label, $value]){!! $row([$k($label), $v($value)]) !!}@endforeach
        </table>
    @endif

    @if ($q->customer_notes)<h3><span class="rule"></span>{!! $t('notes') !!}</h3><div class="note txt">{{ \App\Support\Documents\PdfText::paragraph($q->customer_notes) }}</div>@endif

    {{-- 7. Accept online --}}
    @php
        $acceptBox = '<td style="vertical-align:top;padding-'.($rtl ? 'left' : 'right').':12px"><div class="accept"><div class="label">'.$t('accept_online').'</div>'
            .($q->status === \App\Enums\QuotationStatus::Accepted
                ? '<div>'.$t('accepted_on').' '.e($q->accepted_at?->format('d M Y, H:i')).($q->accepted_name ? ' · '.$x($q->accepted_name) : '').'</div>'
                : '<div style="margin-bottom:6px">'.$t('accept_text').'</div>'
                    .($q->public_token ? '<a class="btn" href="'.e($q->publicUrl()).'">'.$p('open_quote').'</a><div class="muted" style="font-size:7px;margin-top:4px">'.e($q->publicUrl()).'</div>' : ''))
            .'</div></td>';
        $qrCell = $qr ? '<td style="width:96px;vertical-align:top" class="'.($rtl ? '' : 'r').'"><img src="'.$qr.'" alt="" style="width:90px;height:90px"></td>' : null;
    @endphp
    <table style="margin-top:12px;page-break-inside:avoid">{!! $row(array_filter([$acceptBox, $qrCell])) !!}</table>

    {{-- 8. Bank details and terms --}}
    @if ($bank['rows'] || $bank['note'])
        <h3><span class="rule"></span>{!! $t('bank') !!}</h3>
        <table class="incl">
            @foreach ($bank['rows'] as $label => $value){!! $row([$k(e($label)), $v('<strong>'.e($value).'</strong>')]) !!}@endforeach
        </table>
        @if ($bank['note'])<div class="note txt">{{ \App\Support\Documents\PdfText::paragraph($bank['note']) }}</div>@endif
    @endif

    @if ($termsText)
        <h3><span class="rule"></span>{!! $t('terms') !!}</h3>
        <div class="note muted txt" style="font-size:7.4px">{{ \App\Support\Documents\PdfText::paragraph(\Illuminate\Support\Str::limit($termsText, 1400), 110) }}</div>
        @if ($termsUrl)<div style="font-size:7.4px;margin-top:3px{{ $rtl ? ';text-align:right' : '' }}">{!! $t('full_terms') !!}: <a href="{{ $termsUrl }}" style="color:#d7141a">{{ $termsUrl }}</a></div>@endif
    @endif

    @if ($stamp)<div style="margin-top:12px{{ $rtl ? ';text-align:left' : '' }}"><img class="stamp" src="{{ $stamp }}" alt=""></div>@endif
@endsection

@section('foot-note'){{ $p('not_invoice') }}@endsection
