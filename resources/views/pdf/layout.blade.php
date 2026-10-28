@php
    /** Shared look for every customer document (quotation, confirmation, invoice, receipt, statement). */
    $s = \App\Models\Setting::values();
    $logo = \App\Support\Documents\Pdf::logo();
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $vat = \App\Support\Tax::enabled();
    $company = array_filter([
        ($s['company_legal_name'] ?? null) ?: ($s['company_name'] ?? 'RED PRIME RENTALS'),
        $s['company_address'] ?? null,
        collect([$s['company_phone'] ?? null, $s['company_email'] ?? null])->filter()->implode(' · '),
        ! empty($s['company_trade_licence']) ? 'Trade licence '.$s['company_trade_licence'] : null,
        $vat && ! empty($s['company_trn']) ? 'TRN '.$s['company_trn'] : null,
    ]);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 26px 34px 46px; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1f1f23; line-height: 1.45; margin: 0; }
        .bar { height: 5px; background: #d7141a; margin: -26px -34px 18px; }
        .bar span { display: block; height: 5px; width: 28%; background: #d4a62a; margin-left: 72%; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .logo { height: 44px; }
        .eyebrow { font-size: 7.5px; letter-spacing: 1.6px; text-transform: uppercase; color: #a8781a; font-weight: bold; }
        .doc-title { font-size: 21px; font-weight: bold; color: #111; margin: 2px 0 4px; }
        .doc-meta td { padding: 1px 0; font-size: 9px; }
        .doc-meta .k { color: #71717a; padding-right: 10px; }
        .r { text-align: right; }
        .muted { color: #71717a; }
        .box { border: 1px solid #e4e4e7; border-radius: 6px; padding: 10px 12px; }
        .parties td { width: 50%; vertical-align: top; }
        .label { font-size: 7.5px; letter-spacing: 1.2px; text-transform: uppercase; color: #71717a; font-weight: bold; margin-bottom: 4px; }
        .strong { font-weight: bold; font-size: 10.5px; }
        h3 { font-size: 10.5px; margin: 16px 0 6px; color: #111; }
        .lines { margin-top: 14px; }
        .lines th { background: #18181b; color: #fff; font-size: 8px; text-transform: uppercase; letter-spacing: .8px; padding: 7px 8px; text-align: left; }
        .lines th.r { text-align: right; }
        .lines td { padding: 7px 8px; border-bottom: 1px solid #ececef; vertical-align: top; }
        .lines .detail { color: #71717a; font-size: 8.5px; }
        .totals { margin-top: 8px; }
        .totals td { padding: 4px 8px; }
        .totals .grand td { border-top: 2px solid #18181b; font-size: 12.5px; font-weight: bold; padding-top: 7px; }
        .totals .grand td.r { color: #d7141a; }
        .note { margin-top: 14px; font-size: 8.8px; white-space: pre-line; }
        .pill { display: inline-block; padding: 2px 7px; border-radius: 9px; font-size: 7.8px; font-weight: bold; }
        .pill-ok { background: #dcfce7; color: #166534; }
        .pill-warn { background: #fef3c7; color: #92400e; }
        .pill-bad { background: #fee2e2; color: #991b1b; }
        .pill-info { background: #e0f2fe; color: #075985; }
        .info td { padding: 4px 0; border-bottom: 1px solid #f1f1f3; }
        .info td.k { color: #71717a; width: 34%; }
        .sign td { padding-top: 26px; width: 50%; }
        .sign .line { border-top: 1px solid #a1a1aa; padding-top: 4px; font-size: 8px; color: #71717a; width: 85%; }
        .foot { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 7.5px; color: #8b8b93; border-top: 1px solid #ececef; padding-top: 5px; }
        .screen-only { display: none; }
        @if (! ($asPdf ?? true))
            body { max-width: 780px; margin: 24px auto; padding: 0 16px; font-size: 13px; }
            .bar { margin: 0 0 18px; }
            .screen-only { display: block; margin: 18px 0; }
            .screen-only button { font: 600 14px sans-serif; padding: 10px 18px; border: 0; border-radius: 10px; background: #d7141a; color: #fff; cursor: pointer; }
            @media print { .screen-only { display: none; } }
        @endif
    </style>
</head>
<body>
    <div class="bar"><span></span></div>

    <table class="head">
        <tr>
            <td style="width:52%">
                @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
                <div class="muted" style="margin-top:6px;font-size:8.5px">{!! nl2br(e(implode("\n", $company))) !!}</div>
            </td>
            <td class="r">
                <div class="eyebrow">@yield('eyebrow', $s['company_name'] ?? 'RED PRIME RENTALS')</div>
                <div class="doc-title">@yield('doc-title')</div>
                <table class="doc-meta" style="width:auto;margin-left:auto">
                    @yield('meta')
                </table>
            </td>
        </tr>
    </table>

    @yield('content')

    <div class="foot">
        <table><tr>
            <td>{{ $s['company_name'] ?? 'RED PRIME RENTALS' }}@if (! empty($s['company_phone'])) · {{ $s['company_phone'] }}@endif @if (! empty($s['company_email'])) · {{ $s['company_email'] }}@endif @hasSection('foot-note') · @yield('foot-note')@endif</td>
            <td style="width:90px"></td>
        </tr></table>
    </div>

    <div class="screen-only"><button onclick="window.print()">Print / save as PDF</button></div>
</body>
</html>
