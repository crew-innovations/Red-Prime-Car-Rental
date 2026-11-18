@php
    /**
     * Shared look for every customer document (4 Nov 2026, brief 12.3): quotation, confirmation,
     * invoice, credit note, receipt, return statement and job report. Company details come from
     * Business settings › Company profile (App\Support\Documents\CompanyProfile).
     */
    $s = \App\Models\Setting::values();
    $profile = \App\Support\Documents\CompanyProfile::values();
    $logo = \App\Support\Documents\CompanyProfile::logo();
    $aed = fn ($v) => 'AED '.number_format((float) $v, 2);
    $vat = \App\Support\Tax::enabled();
    $pdfText ??= new \App\Support\Documents\PdfText('en');
    $font = fn (string $file) => str_replace('\\', '/', resource_path('fonts/'.$file));
    $contact = collect([$profile['phone'], $profile['whatsapp'] && $profile['whatsapp'] !== $profile['phone'] ? 'WhatsApp '.$profile['whatsapp'] : null, $profile['email'], $profile['website']])->filter()->implode('  ·  ');
    $ids = collect([
        $profile['licence'] ? 'Trade licence '.$profile['licence'] : null,
        $profile['trn'] ? 'TRN '.$profile['trn'] : null,
    ])->filter()->implode('  ·  ');
@endphp
<!doctype html>
<html lang="{{ $pdfText->lang === 'ar' ? 'ar' : 'en' }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @font-face { font-family: 'Poppins'; font-weight: normal; font-style: normal; src: url('{{ $font('Poppins-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Poppins'; font-weight: 500; font-style: normal; src: url('{{ $font('Poppins-Medium.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Poppins'; font-weight: bold; font-style: normal; src: url('{{ $font('Poppins-Bold.ttf') }}') format('truetype'); }
        @page { margin: 30px 36px 64px; }
        * { box-sizing: border-box; }
        body { font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 8.6px; color: #1f1f23; line-height: 1.38; margin: 0; }
        .ar, .ar-sub { font-family: 'DejaVu Sans', sans-serif; letter-spacing: 0; text-transform: none; }
        .ar-sub { font-size: .86em; color: #8b8b93; font-weight: normal; white-space: nowrap; }
        .bar { height: 6px; background: #d7141a; margin: -30px -36px 20px; }
        .bar span { display: block; height: 6px; width: 26%; background: #d4a62a; margin-left: 74%; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .logo { height: 46px; }
        .co-name { font-weight: bold; font-size: 9.5px; color: #111; margin-top: 7px; }
        .co { color: #6b6b73; font-size: 7.6px; line-height: 1.35; }
        .eyebrow { font-size: 7px; letter-spacing: 1.8px; text-transform: uppercase; color: #a8781a; font-weight: bold; }
        .doc-title { font-size: 22px; font-weight: bold; color: #111; margin: 1px 0 5px; letter-spacing: -.2px; }
        .doc-meta td { padding: 1.5px 0; font-size: 8.4px; }
        .doc-meta .k { color: #71717a; padding-right: 12px; }
        .r { text-align: right; }
        .c { text-align: center; }
        .muted { color: #71717a; }
        .box { border: 1px solid #e7e5e4; border-radius: 7px; padding: 10px 12px; }
        .box-soft { background: #faf8f5; border: 1px solid #efe9df; border-radius: 7px; padding: 10px 12px; }
        .parties td { width: 50%; vertical-align: top; }
        .label { font-size: 7px; letter-spacing: 1.3px; text-transform: uppercase; color: #a8781a; font-weight: bold; margin-bottom: 4px; }
        .strong { font-weight: bold; font-size: 10px; color: #111; }
        h3 { font-size: 9.6px; margin: 16px 0 6px; color: #111; font-weight: bold; }
        h3 .rule { display: inline-block; width: 4px; height: 9px; background: #d7141a; margin-right: 6px; border-radius: 2px; }
        .lines { margin-top: 14px; }
        .lines th { background: #18181b; color: #fff; font-size: 7px; text-transform: uppercase; letter-spacing: .9px; padding: 7px 8px; text-align: left; font-weight: bold; }
        .lines th.r { text-align: right; }
        .lines td { padding: 7px 8px; border-bottom: 1px solid #ececef; vertical-align: top; }
        .lines .detail { color: #71717a; font-size: 7.6px; }
        .lines .off { color: #b91c1c; }
        .totals { margin-top: 6px; }
        .totals td { padding: 3.5px 8px; }
        .totals .grand td { border-top: 2px solid #18181b; font-size: 12px; font-weight: bold; padding-top: 7px; color: #111; }
        .totals .grand td.r { color: #d7141a; }
        .note { margin-top: 4px; font-size: 8.2px; white-space: pre-line; }
        .pill { display: inline-block; padding: 1.5px 7px; border-radius: 9px; font-size: 7px; font-weight: bold; }
        .pill-ok { background: #dcfce7; color: #166534; }
        .pill-warn { background: #fef3c7; color: #92400e; }
        .pill-bad { background: #fee2e2; color: #991b1b; }
        .pill-info { background: #e0f2fe; color: #075985; }
        .pill-red { background: #fde8e8; color: #b0101a; }
        .info td { padding: 3px 0; border-bottom: 1px solid #f1f1f3; }
        .info td.k { color: #71717a; width: 34%; }
        .sign td { padding-top: 26px; width: 50%; }
        .sign .line { border-top: 1px solid #a1a1aa; padding-top: 4px; font-size: 7.6px; color: #71717a; width: 85%; }
        .foot { position: fixed; bottom: -46px; left: 0; right: 0; font-size: 6.8px; color: #8b8b93; border-top: 1px solid #ececef; padding-top: 6px; line-height: 1.4; }
        .foot b { color: #52525b; }
        .rtl .note, .rtl .txt { text-align: right; }
        .screen-only { display: none; }
        @if (! ($asPdf ?? true))
            body { max-width: 800px; margin: 24px auto; padding: 0 16px; font-size: 13px; }
            .bar { margin: 0 0 18px; }
            .foot { position: static; margin-top: 30px; }
            .screen-only { display: block; margin: 18px 0; }
            .screen-only button { font: 600 14px sans-serif; padding: 10px 18px; border: 0; border-radius: 10px; background: #d7141a; color: #fff; cursor: pointer; }
            @media print { .screen-only { display: none; } }
        @endif
        @yield('styles')
    </style>
</head>
<body class="{{ $pdfText->rtl() ? 'rtl' : '' }}">
    <div class="foot">
        <table><tr>
            <td>
                <b>{{ $profile['legal_name'] }}</b>@if ($ids)  ·  {{ $ids }}@endif
                @if ($profile['address'])<br>{{ \Illuminate\Support\Str::of($profile['address'])->replace(["\r\n", "\n"], ', ') }}@endif
                @if ($contact)<br>{{ $contact }}@endif
                @hasSection('foot-note')<br>@yield('foot-note')@endif
            </td>
            <td style="width:96px"></td>
        </tr></table>
    </div>

    <div class="bar"><span></span></div>

    <table class="head">
        <tr>
            <td style="width:54%">
                @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
                <div class="co-name">{{ $profile['legal_name'] }}</div>
                <div class="co">
                    @if ($profile['address']){!! nl2br(e($profile['address'])) !!}<br>@endif
                    @if ($contact){{ $contact }}<br>@endif
                    @if ($ids){{ $ids }}@endif
                </div>
            </td>
            <td class="r">
                <div class="eyebrow">@yield('eyebrow', $profile['trade_name'])</div>
                <div class="doc-title">@yield('doc-title')</div>
                <table class="doc-meta" style="width:auto;margin-left:auto">
                    @yield('meta')
                </table>
            </td>
        </tr>
    </table>

    @yield('content')

    <div class="screen-only"><button onclick="window.print()">Print / save as PDF</button></div>
</body>
</html>
