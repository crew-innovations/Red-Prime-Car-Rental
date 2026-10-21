@extends('public.layout')

@section('title', 'Preview · '.($p['name'] ?? ''))
@section('tag', 'Website preview')

@section('content')
    <div class="alert {{ $live ? 'ok' : 'bad' }}">
        {{ $live ? 'This is live on the website.' : 'Not visible on the website yet: '.$why }}
        Staff-only preview of what the website receives from the CRM.
    </div>

    @if (! empty($p['images']['cover']['large']))
        <img src="{{ $p['images']['cover']['large'] }}" alt="" style="width:100%;border-radius:16px;margin-bottom:16px;aspect-ratio:16/9;object-fit:cover">
    @endif

    <div class="card">
        <h1>{{ $p['name'] }}</h1>
        <div class="muted">
            {{ collect([$p['category']['name'] ?? (is_string($p['category'] ?? null) ? $p['category'] : null), $p['product_type'] ?? null, $p['duration']['label'] ?? null, isset($p['duration_minutes']) ? round($p['duration_minutes'] / 60, 1).' hours' : null])->filter()->implode(' · ') }}
        </div>
        @if (! empty($p['summary']))<p>{{ $p['summary'] }}</p>@endif
        @php
            $price = $p['price'] ?? [];
            $from = $price['from_amount'] ?? $price['amount'] ?? null;
            $unit = ['per_person' => 'per person', 'per_package' => 'per package', 'per_trip' => 'per trip'][$price['unit'] ?? ''] ?? '';
        @endphp
        <p style="font-size:20px;font-weight:700;margin:12px 0 0">
            {{ $from !== null ? ($price['currency'] ?? 'AED').' '.number_format($from) : 'No price set' }}
            <span class="muted" style="font-weight:400">{{ $unit }}</span>
        </p>
        @if (! empty($price['group_prices']))
            <div class="muted">Group prices: @foreach ($price['group_prices'] as $g){{ $g['min_guests'] }}+ guests {{ number_format($g['amount']) }}@if (! $loop->last), @endif @endforeach</div>
        @endif
    </div>

    @if (! empty($p['description_html']))
        <div class="card">{!! $p['description_html'] !!}</div>
    @endif

    @if (! empty($p['itinerary']))
        <div class="card">
            <h2>Itinerary</h2>
            @foreach ($p['itinerary'] as $day)
                <div style="padding:10px 0;border-bottom:1px solid var(--line)">
                    <b>{{ isset($day['day']) ? 'Day '.$day['day'].' · ' : (($day['time'] ?? null) ? $day['time'].' · ' : '') }}{{ $day['title'] }}</b>
                    @if (! empty($day['description']))<div class="muted">{{ $day['description'] }}</div>@endif
                    @php $bits = collect([implode(', ', $day['activities'] ?? []), $day['accommodation'] ?? null, ! empty($day['meals']) ? 'Meals: '.implode(', ', $day['meals']) : null])->filter(); @endphp
                    @if ($bits->isNotEmpty())<div class="muted">{{ $bits->implode(' · ') }}</div>@endif
                </div>
            @endforeach
        </div>
    @endif

    @if (! empty($p['departures']))
        <div class="card">
            <h2>Departures</h2>
            <table>
                @foreach ($p['departures'] as $d)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($d['start_date'] ?? $d['date'])->format('D, d M Y') }}{{ ! empty($d['start_time']) ? ' · '.$d['start_time'] : '' }}</td>
                        <td class="r">{{ $d['seats_left'] === null ? 'Open' : $d['seats_left'].' left' }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if (! empty($p['included']) || ! empty($p['inclusions']))
        <div class="card">
            <h2>Included</h2>
            <div class="muted">{{ implode(' · ', $p['included'] ?? $p['inclusions']) }}</div>
            @if (! empty($p['excluded']) || ! empty($p['exclusions']))
                <h2 style="margin-top:14px">Not included</h2>
                <div class="muted">{{ implode(' · ', $p['excluded'] ?? $p['exclusions']) }}</div>
            @endif
        </div>
    @endif

    @if (! empty($p['terms']))
        <div class="card"><h2>Terms</h2><div class="muted" style="white-space:pre-line">{{ $p['terms'] }}</div></div>
    @endif
@endsection
