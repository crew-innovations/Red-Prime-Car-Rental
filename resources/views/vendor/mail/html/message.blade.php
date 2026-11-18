<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="\App\Support\Mail\Branding::homeUrl()">
{{ \App\Support\Mail\Branding::name() }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: company profile (Business settings) --}}
<x-slot:footer>
<x-mail::footer>
{!! \App\Support\Mail\Branding::footerHtml() !!}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
