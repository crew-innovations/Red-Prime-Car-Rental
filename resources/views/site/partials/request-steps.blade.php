{{-- Shared steps 2 and 3 of every booking panel: contact details, then the success message.
     30 Oct: "Book now" makes a Pending payment booking in the CRM (falls back to a request when it can't). --}}
@php
    use App\Support\Site\Site;
    $canPay = ($offerPay ?? true) && \App\Support\Payments\Payments::connected() !== [];
@endphp
<div class="bk-step" data-step="details">
    <button type="button" class="btn btn-ghost btn-s" data-back style="justify-self:start"><svg class="i i-s flip"><use href="#i-chev-l"/></svg>{{ __('site.quote.back') }}</button>
    <h3 style="font-size:18px">{{ __('site.quote.your_details') }}</h3>
    <div class="field"><label for="{{ $p }}-name">{{ __('site.quote.name') }}</label><input class="input" id="{{ $p }}-name" name="name" autocomplete="name" maxlength="120" required></div>
    <div class="field"><label for="{{ $p }}-phone">{{ __('site.quote.phone') }}</label><input class="input" id="{{ $p }}-phone" name="phone" type="tel" autocomplete="tel" placeholder="{{ __('site.quote.phone_ph') }}" maxlength="30" required dir="ltr"></div>
    <div class="field"><label for="{{ $p }}-email">{{ __('site.booking.email_needed') }}</label><input class="input" id="{{ $p }}-email" name="email" type="email" autocomplete="email" maxlength="255" dir="ltr" required></div>
    <div class="field"><label for="{{ $p }}-msg">{{ __('site.quote.message') }}</label><textarea class="textarea" id="{{ $p }}-msg" name="message" rows="3" maxlength="1500"></textarea></div>
    <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
    @if ($canPay)
        <div class="field">
            <span class="label">{{ __('site.booking.pay_when') }}</span>
            <label class="switch"><span>{{ __('site.booking.pay_now') }}</span><input type="radio" name="pay_when" value="now"><span class="knob"></span></label>
            <label class="switch"><span>{{ __('site.booking.pay_later') }}</span><input type="radio" name="pay_when" value="later" checked><span class="knob"></span></label>
            <p class="est-note">{{ __('site.booking.pay_choice_note') }}</p>
        </div>
    @endif
    <p class="err-msg" data-form-error hidden></p>
    <button type="submit" class="btn btn-red btn-block btn-l" data-send>{{ $sendLabel ?? __('site.booking.book_now') }}</button>
    <p class="est-note">@unless ($canPay){{ $sendNote ?? __('site.booking.pay_note') }} @endunless{{ __('site.quote.privacy') }}</p>
</div>
<div class="bk-step" data-step="done">
    <div class="bk-success">
        <div class="ico"><svg class="i i-l"><use href="#i-check"/></svg></div>
        <div data-done-booked hidden>
            <h3>{{ __('site.booking.done_title') }}</h3>
            <p class="muted" data-done-text data-tpl="{{ __('site.booking.done_text') }}" data-tpl-later="{{ __('site.booking.done_later') }}"></p>
            <p class="muted small mt-1" data-done-hold data-tpl="{{ __('site.booking.hold') }}"></p>
        </div>
        <div data-done-ride hidden>
            <h3>{{ __('site.limo.ride_done_title') }}</h3>
            <p class="muted" data-ride-text data-tpl="{{ __('site.limo.ride_done_text') }}"></p>
            <a class="btn btn-red btn-block mt-2" data-ride-link href="#">{{ __('site.limo.open_ride') }}</a>
        </div>
        <div data-done-request>
            <h3>{{ __('site.quote.success_title') }}</h3>
            <p class="muted">{{ __('site.quote.success_text') }}</p>
        </div>
        <div><small class="muted">{{ __('site.quote.reference') }}</small><br><span class="bk-ref" data-ref></span></div>
        @if (Site::whatsapp())<a class="btn btn-wa btn-block" data-wa-follow href="{{ Site::whatsapp() }}" target="_blank" rel="noopener"><svg class="i"><use href="#i-whatsapp"/></svg>{{ __('site.quote.wa_follow') }}</a>@endif
    </div>
</div>
