<x-mail::message>
@if (! empty($rtl))
<div class="rp-rtl" dir="rtl">

@endif
{{-- Greeting --}}
@if (($greeting ?? null) === ' ')
{{-- no greeting: the text written in the CRM starts the email --}}
@elseif (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
{{ \App\Support\Mail\Branding::name() }}
@endif
@if (! empty($rtl))

</div>
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
{{ ! empty($rtl) ? 'إذا لم يعمل زر "'.$actionText.'"، انسخ الرابط التالي والصقه في المتصفح:' : 'If the "'.$actionText.'" button does not work, copy this link into your browser:' }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
