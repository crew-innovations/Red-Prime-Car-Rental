{{-- Three signature blocks: Hirer, Driver, Company (reference layout). --}}
<table class="sig3" style="margin-top:10px; width:100%">
    <tr>
        <td>
            @if ($sigData)<img class="sig-img" src="{{ $sigData }}" alt="">@endif
            <div class="line">{!! $t('sign_hirer') !!}@if ($c->signed_at) · {{ $x($c->signed_by_name) }} · {!! $t('signed_on') !!} {{ $c->signed_at->format('d M Y, H:i') }}@endif</div>
        </td>
        <td>
            @if ($driverSigData)<img class="sig-img" src="{{ $driverSigData }}" alt="">@endif
            <div class="line">{!! $t('sign_driver') !!}@if ($c->driver_signed_at) · {{ $x($c->driver_signed_by_name) }} · {!! $t('signed_on') !!} {{ $c->driver_signed_at->format('d M Y, H:i') }}@endif</div>
        </td>
        <td>
            @if ($stamp)<img class="stamp" src="{{ $stamp }}" alt="">@endif
            <div class="line">{!! $t('sign_company') !!} · {{ $x($companyName) }}{{ $c->creator ? ' · '.$c->creator->name : '' }}</div>
        </td>
    </tr>
</table>
