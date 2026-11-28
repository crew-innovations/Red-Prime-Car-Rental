@if (\App\Support\Site\WebsiteAssistant::ready())
<aside class="assist" data-assist data-slug="{{ $slug ?? '' }}" data-locale="{{ app()->getLocale() === 'ar' ? 'ar' : 'en' }}" data-greet="{{ __('site.assistant.greeting') }}" data-unavailable="{{ __('site.assistant.unavailable') }}">
    <div class="assist-panel" hidden data-assist-panel>
        <button type="button" class="assist-x" data-assist-close aria-label="{{ __('site.assistant.close') }}">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
        <div class="assist-head">
            <span class="brand-logo assist-word">
                <img class="logo-on-dark" src="{{ asset('images/brand/logo-dark-s.webp') }}" alt="{{ \App\Support\Site\Site::company() }}" width="346" height="196">
                <img class="logo-on-light" src="{{ asset('images/brand/logo-light-s.webp') }}" alt="" aria-hidden="true" width="346" height="196">
            </span>
            <div class="assist-id">
                <strong>{{ __('site.assistant.title') }}</strong>
                <span class="assist-online"><i></i>{{ __('site.assistant.online') }}</span>
            </div>
            <button type="button" class="assist-icon" data-assist-restart aria-label="{{ __('site.assistant.restart') }}">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5"/></svg>
            </button>
        </div>
        <div class="assist-log" data-assist-log></div>
        <div class="assist-chips">
            <button type="button" class="assist-chip" data-assist-chip data-assist-text="{{ __('site.assistant.ask_cars') }}">{{ __('site.assistant.cars') }}</button>
            <button type="button" class="assist-chip" data-assist-chip data-assist-text="{{ __('site.assistant.ask_price') }}">{{ __('site.assistant.price') }}</button>
            <button type="button" class="assist-chip" data-assist-chip data-assist-text="{{ __('site.assistant.ask_book') }}">{{ __('site.assistant.book_steps') }}</button>
            <button type="button" class="assist-chip assist-chip-team" data-assist-chip="team">{{ __('site.assistant.team') }}</button>
            @if (! empty($slug))
            <button type="button" class="assist-chip" data-assist-chip="book">{{ __('site.assistant.book') }}</button>
            @endif
        </div>
        <form class="assist-extra" hidden data-assist-contact>
            <input type="text" data-assist-name placeholder="{{ __('site.assistant.name') }}" autocomplete="name">
            <input type="tel" data-assist-phone placeholder="{{ __('site.assistant.phone') }}" autocomplete="tel">
            <button type="submit" class="assist-chip assist-chip-team">{{ __('site.assistant.ask_call') }}</button>
        </form>
        @if (! empty($slug))
        <form class="assist-extra" hidden data-assist-bookbox>
            <input type="text" data-assist-book-name placeholder="{{ __('site.assistant.name') }}" autocomplete="name">
            <input type="tel" data-assist-book-phone placeholder="{{ __('site.assistant.phone') }}" autocomplete="tel">
            <input type="email" data-assist-book-email placeholder="{{ __('site.assistant.email') }}" autocomplete="email">
            <input type="text" data-assist-start placeholder="{{ __('site.assistant.start') }}" inputmode="numeric">
            <input type="text" data-assist-end placeholder="{{ __('site.assistant.end') }}" inputmode="numeric">
            @if (\App\Support\Payments\Payments::connected() !== [])
            <button type="button" class="assist-go" data-assist-book data-pay="1">{{ __('site.booking.pay_now') }}</button>
            <button type="button" class="assist-go assist-go-quiet" data-assist-book data-pay="0">{{ __('site.booking.pay_later') }}</button>
            @else
            <button type="button" class="assist-go" data-assist-book data-pay="0">{{ __('site.assistant.book') }}</button>
            @endif
        </form>
        @endif
        <form class="assist-form" data-assist-form>
            <input type="text" name="website" class="assist-hp" tabindex="-1" autocomplete="off">
            <input type="text" data-assist-message maxlength="2000" placeholder="{{ __('site.assistant.placeholder') }}" required>
            <button type="submit" class="assist-send" aria-label="{{ __('site.assistant.send') }}">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </form>
        <p class="assist-note">{{ __('site.assistant.note') }}</p>
    </div>
    <button type="button" class="assist-launch" data-assist-open aria-label="{{ __('site.assistant.open') }}">
        <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path fill="currentColor" d="M12 3a9 9 0 0 0-9 9c0 1.7.5 3.3 1.3 4.7L3 21l4.5-1.2A9 9 0 1 0 12 3zm-3.2 10.2a1.2 1.2 0 1 1 0-2.4 1.2 1.2 0 0 1 0 2.4zm3.2 0a1.2 1.2 0 1 1 0-2.4 1.2 1.2 0 0 1 0 2.4zm3.2 0a1.2 1.2 0 1 1 0-2.4 1.2 1.2 0 0 1 0 2.4z"/></svg>
    </button>
</aside>
@endif
