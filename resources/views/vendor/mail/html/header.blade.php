@props(['url'])
{{-- RED PRIME header (4 Nov 2026): the company logo, never a remote placeholder. --}}
@php($logo = \App\Support\Mail\Branding::logoUrl())
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logo)
<img src="{{ $logo }}" alt="{{ trim(strip_tags($slot)) }}" style="height: 54px; max-height: 54px; width: auto;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
