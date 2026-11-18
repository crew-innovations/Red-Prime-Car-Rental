@extends('site.layout')
@section('body_class', 'has-mbar')
@php
    use App\Support\Site\Site;
    use Illuminate\Support\Carbon;
    $isPackage = ($kind ?? 'trip') === 'package';
    $item = $isPackage ? $package : $trip;
    $amount = $isPackage ? ($item['price']['from_amount'] ?? null) : ($item['price']['amount'] ?? null);
    $unit = $item['price']['unit'] ?? 'per_person';
    $deps = collect($item['departures'] ?? []);
    $onRequest = $isPackage ? (($item['booking_mode'] ?? null) !== 'departures') : $deps->isEmpty();
    $maxGuests = (int) ($item['guests']['max'] ?? 50) ?: 50;
    $minGuests = max(1, (int) ($item['guests']['min'] ?? 1));
    $wa = Site::whatsapp(__('site.wa.hello', ['item' => $item['name']]));
    $listRoute = $isPackage ? 'packages' : 'trips';
    $listLabel = $isPackage ? __('site.nav.packages') : __('site.nav.trips');
    $minDate = now()->addDays((int) ($item['booking_cutoff_days'] ?? 0))->toDateString();
    $included = $isPackage ? ($item['inclusions'] ?? []) : ($item['included'] ?? []);
    $excluded = $isPackage ? ($item['exclusions'] ?? []) : ($item['excluded'] ?? []);
@endphp

@section('content')
<div class="wrap" style="padding-top:22px">
    <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><a href="{{ Site::route($listRoute) }}">{{ $listLabel }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ $item['name'] }}</span></nav>
</div>

<div class="wrap detail">
    <div>
        @include('site.partials.gallery', ['images' => $item['images'] ?? [], 'name' => $item['name'], 'kind' => 'yacht', 'badges' => ! empty($item['badge']) ? '<span class="badge badge-gold">'.e($item['badge']).'</span>' : ''])
        <div class="d-head">
            <span class="card-tag">{{ $isPackage ? collect([$item['category']['name'] ?? null, $item['duration']['label'] ?? null])->filter()->implode(' · ') : collect([$item['category'] ?? null, $item['departure_marina'] ?? ($item['location'] ?? null)])->filter()->implode(' · ') }}</span>
            <h1>{{ $item['name'] }}</h1>
            @if (! empty($item['summary']))<p class="muted" style="font-size:17px">{{ $item['summary'] }}</p>@endif
        </div>
        <div class="keyspecs">
            @if ($isPackage && ! empty($item['duration']['label']))<div class="keyspec"><svg class="i"><use href="#i-clock"/></svg><small>{{ __('site.packages.duration') }}</small><b>{{ $item['duration']['label'] }}</b></div>@endif
            @if (! $isPackage && ! empty($item['duration_minutes']))<div class="keyspec"><svg class="i"><use href="#i-clock"/></svg><small>{{ __('site.trips.duration') }}</small><b>{{ __('site.common.minutes', ['n' => $item['duration_minutes']]) }}</b></div>@endif
            @if (! $isPackage && ! empty($item['start_time']))<div class="keyspec"><svg class="i"><use href="#i-calendar"/></svg><small>{{ __('site.trips.departs') }}</small><b>{{ $item['start_time'] }}</b></div>@endif
            @if (! empty($item['guests']['max']))<div class="keyspec"><svg class="i"><use href="#i-users"/></svg><small>{{ __('site.quote.guests') }}</small><b>{{ __('site.common.guests', ['n' => $item['guests']['max']]) }}</b></div>@endif
            @if ($amount)<div class="keyspec"><svg class="i"><use href="#i-tag"/></svg><small>{{ __('site.common.from') }}</small><b>{{ Site::money($amount) }}</b></div>@endif
        </div>

        @if (! empty($item['description_html']))<section class="block"><h2>{{ __('site.car.overview') }}</h2><div class="prose">{!! Site::safeHtml($item['description_html']) !!}</div></section>@endif

        @if (! empty($item['itinerary']))
        <section class="block"><h2>{{ $isPackage ? __('site.packages.itinerary') : __('site.trips.itinerary') }}</h2>
            <ol class="timeline">
                @foreach ($item['itinerary'] as $step)
                    <li>
                        <small>{{ $isPackage ? __('site.packages.day', ['n' => $step['day']]) : ($step['time'] ?? '') }}</small>
                        <b>{{ $step['title'] ?? '' }}</b>
                        @if (! empty($step['description']))<p>{{ strip_tags($step['description']) }}</p>@endif
                        @if (! empty($step['activities']))<p>{{ implode(' · ', $step['activities']) }}</p>@endif
                    </li>
                @endforeach
            </ol>
        </section>
        @endif

        @if ($included)<section class="block"><h2>{{ __('site.trips.included') }}</h2><ul class="feat-list">@foreach ($included as $x)<li><svg class="i"><use href="#i-check-c"/></svg>{{ is_array($x) ? ($x['name'] ?? '') : $x }}</li>@endforeach</ul></section>@endif
        @if ($excluded)<section class="block"><h2>{{ __('site.trips.excluded') }}</h2><ul class="feat-list">@foreach ($excluded as $x)<li><svg class="i"><use href="#i-x"/></svg>{{ is_array($x) ? ($x['name'] ?? '') : $x }}</li>@endforeach</ul></section>@endif
        @if (! empty($item['price']['group_prices']))
            <section class="block"><h2>{{ __('site.packages.group') }}</h2><table class="table"><tbody>@foreach ($item['price']['group_prices'] as $g)<tr><th scope="row">{{ __('site.packages.group_from', ['n' => $g['min_guests']]) }}</th><td>{{ Site::money($g['amount']) }} <span class="muted">{{ __('site.common.per_person') }}</span></td></tr>@endforeach</tbody></table></section>
        @endif
        @if (! empty($item['meeting_point']))<section class="block"><h2>{{ __('site.trips.meeting') }}</h2><p class="prose">{{ $item['meeting_point'] }}</p></section>@endif
        @foreach (['requirements' => __('site.trips.requirements'), 'instructions' => __('site.trips.instructions'), 'terms' => __('site.trips.cancellation')] as $k => $label)
            @if (! empty($item[$k]) && is_string($item[$k]))<section class="block"><h2>{{ $label }}</h2><div class="prose" style="white-space:pre-line">{{ strip_tags($item[$k]) }}</div></section>@endif
        @endforeach
    </div>

    <aside id="book">
        <form class="bk" data-quote="{{ $isPackage ? 'package' : 'yacht_trip' }}" data-slug="{{ $item['slug'] }}" data-item="{{ $item['name'] }}" data-on-request="{{ $onRequest ? 1 : 0 }}" novalidate>
            <div class="bk-head"><small>{{ __('site.common.from') }}</small><div class="from">@if ($amount){{ Site::money($amount) }} <span>{{ __('site.common.'.$unit) }}</span>@else{{ __('site.common.price_on_request') }}@endif</div></div>
            <div class="bk-body">
                <div class="bk-step on" data-step="quote">
                    @if (! $onRequest)
                        <div class="field"><span class="label">{{ __('site.trips.choose_date') }}</span>
                            <div class="deps">
                                @foreach ($deps as $i => $d)
                                    @php $date = Carbon::parse($d['date'] ?? $d['start_date']); @endphp
                                    <label class="dep"><input type="radio" name="departure_id" value="{{ $d['id'] }}" @disabled(! $d['available']) @checked($i === $deps->search(fn ($x) => $x['available']))>
                                        <small>{{ $date->translatedFormat('D') }}</small><b>{{ $date->translatedFormat('j M') }}</b>
                                        <small>{{ $d['start_time'] ?? '' }}@if (isset($d['seats_left'])) · {{ trans_choice('site.trips.seats_left', $d['seats_left'], ['count' => $d['seats_left']]) }}@endif</small>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="field"><label for="tp-date">{{ __('site.quote.date') }}</label><input class="input" id="tp-date" type="date" name="date" min="{{ $minDate }}"></div>
                        @if (! $isPackage)<p class="muted small">{{ __('site.trips.no_dates') }}</p>@endif
                    @endif
                    <div class="field"><label for="tp-guests">{{ __('site.quote.guests') }}</label>
                        <div class="stepper" data-stepper><button type="button" data-step="-1" aria-label="-">−</button><input id="tp-guests" type="number" name="guests" min="{{ $minGuests }}" max="{{ $maxGuests }}" value="{{ max($minGuests, 2) }}"><button type="button" data-step="1" aria-label="+">+</button></div>
                    </div>
                    <div class="est" data-est aria-live="polite"><p class="muted small">{{ __('site.quote.pick_dates') }}</p></div>
                    <button type="button" class="btn btn-red btn-block btn-l" data-next>{{ __('site.booking.book_now') }} <svg class="i flip"><use href="#i-arrow"/></svg></button>
                    @if ($wa)<a class="btn btn-ghost btn-block" href="{{ $wa }}" target="_blank" rel="noopener"><svg class="i" style="color:#25d366"><use href="#i-whatsapp"/></svg>{{ __('site.common.ask_whatsapp') }}</a>@endif
                </div>
                @include('site.partials.request-steps', ['p' => 'tp'])
            </div>
        </form>
    </aside>
</div>

<div class="mbar">
    <div class="price"><small>{{ __('site.quote.mobile_from') }}</small><b>@if ($amount){{ Site::money($amount) }} <span>{{ __('site.common.'.$unit) }}</span>@else{{ __('site.common.price_on_request') }}@endif</b></div>
    <a class="btn btn-red" href="#book">{{ __('site.common.book_now') }}</a>
</div>
@endsection
