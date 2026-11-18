@extends('public.layout')
@section('title', 'New lead')
@section('tag', 'Accept a lead')

{{-- 5 Nov (S3, B.7): opening this page never accepts the lead; only the button does. --}}
@section('content')
    @if ($done === 'won')
        <div class="alert ok"><b>The lead is yours.</b> Call or message them now. Everything from this lead belongs to you.</div>
    @elseif ($done === 'taken' || ($status === 'taken' && $done !== null))
        <div class="alert bad"><b>Taken by {{ $owner ?? 'another agent' }}.</b> Someone accepted it a moment before you.</div>
    @endif

    <div class="card">
        @if ($status === 'invalid')
            <h1>This link doesn't work</h1>
            <p class="muted" style="margin:0">It may be incomplete or meant for someone else. Open the CRM to see your leads.</p>
        @else
            <span class="pill {{ $status === 'open' ? 'warn' : ($status === 'yours' ? 'ok' : 'info') }}">
                {{ match ($status) { 'open' => 'Waiting for an agent', 'yours' => 'Yours', 'taken' => 'Taken by '.($owner ?? 'another agent'), 'closed' => 'Closed', 'expired' => 'Link expired', default => '' } }}
            </span>
            <h1 style="margin-top:10px">{{ $summary }}</h1>
            @if ($lead)
                <table style="margin-top:8px">
                    @if ($lead->service_type)<tr><td class="muted">Wants</td><td class="r">{{ $lead->interestedIn?->name ?? $lead->service_type->getLabel() }}</td></tr>@endif
                    @if ($lead->preferred_date)<tr><td class="muted">Dates</td><td class="r">{{ $lead->preferred_date->format('d M Y') }}{{ $lead->end_date ? ' → '.$lead->end_date->format('d M Y') : '' }}</td></tr>@endif
                    @if ($lead->guests)<tr><td class="muted">Guests</td><td class="r">{{ $lead->guests }}</td></tr>@endif
                    @if ($lead->leadSource)<tr><td class="muted">Source</td><td class="r">{{ $lead->leadSource->name }}</td></tr>@endif
                    @if ($status === 'yours' && $lead->phone)<tr><td class="muted">Phone</td><td class="r"><a href="tel:{{ $lead->phone }}">{{ $lead->phone }}</a></td></tr>@endif
                </table>
            @endif

            @if ($status === 'open')
                <form method="post" action="{{ route('lead-claim.accept', ['token' => $token]) }}" style="margin-top:16px">
                    @csrf
                    <button type="submit" class="btn btn-primary" style="width:100%;font-size:17px;padding:16px">Accept this lead</button>
                </form>
                <p class="muted" style="margin:10px 0 0">The first agent to accept owns the lead.@if ($agent) This link is for {{ $agent }} only.@endif</p>
            @elseif ($status === 'expired')
                <p class="muted" style="margin:12px 0 0">This link works once and for 24 hours. Open the CRM to see if the lead is still waiting.</p>
            @elseif ($status === 'closed')
                <p class="muted" style="margin:12px 0 0">This lead is no longer open.</p>
            @endif
        @endif
    </div>

    <a class="btn btn-ghost" style="width:100%" href="{{ $status === 'yours' ? $crmUrl : url('/crm') }}">Open in CRM</a>
@endsection
