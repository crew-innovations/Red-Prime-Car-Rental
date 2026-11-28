@if (\App\Support\Site\WebsiteAssistant::ready())
<aside class="assist" data-assist data-slug="{{ $slug ?? '' }}" data-locale="{{ app()->getLocale() === 'ar' ? 'ar' : 'en' }}" data-greet="{{ __('site.assistant.greeting') }}">
    <button type="button" class="btn btn-red assist-open" data-assist-open>{{ __('site.assistant.open') }}</button>
    <div class="assist-panel" hidden data-assist-panel>
        <div class="assist-head">
            <strong>{{ __('site.assistant.title') }}</strong>
            <button type="button" class="assist-x" data-assist-close aria-label="{{ __('site.assistant.close') }}">×</button>
        </div>
        <div class="assist-log" data-assist-log></div>
        @if (! empty($slug))
        <div class="assist-book">
            <input type="text" data-assist-name placeholder="{{ __('site.assistant.name') }}" autocomplete="name">
            <input type="tel" data-assist-phone placeholder="{{ __('site.assistant.phone') }}" autocomplete="tel">
            <input type="email" data-assist-email placeholder="{{ __('site.assistant.email') }}" autocomplete="email">
            <input type="text" data-assist-start placeholder="{{ __('site.assistant.start') }}" inputmode="numeric">
            <input type="text" data-assist-end placeholder="{{ __('site.assistant.end') }}" inputmode="numeric">
            @if (\App\Support\Payments\Payments::connected() !== [])
            <button type="button" class="btn btn-red" data-assist-book data-pay="1">{{ __('site.booking.pay_now') }}</button>
            <button type="button" class="btn" data-assist-book data-pay="0">{{ __('site.booking.pay_later') }}</button>
            @else
            <button type="button" class="btn btn-red" data-assist-book data-pay="0">{{ __('site.assistant.book') }}</button>
            @endif
        </div>
        @endif
        <form class="assist-form" data-assist-form>
            <input type="text" name="website" class="assist-hp" tabindex="-1" autocomplete="off">
            <input type="text" data-assist-message maxlength="2000" placeholder="{{ __('site.assistant.placeholder') }}" required>
            <button type="submit" class="btn btn-red">{{ __('site.assistant.send') }}</button>
        </form>
    </div>
</aside>
@endif
