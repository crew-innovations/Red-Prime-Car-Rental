{{-- Contact fields shared by the quick-quote popup and the enquiry forms on service and collection pages.
     Sends to POST /api/v1/enquiries, which creates a lead in the CRM (site.js, form[data-enquiry]). --}}
@php $p = $p ?? 'q'; $service = $service ?? 'car'; @endphp
<div data-enq-form class="grid" style="gap:14px">
    <div class="field"><label for="{{ $p }}-service">{{ __('site.contact.service') }}</label>
        <select class="select" id="{{ $p }}-service" name="service">@foreach (__('site.contact.services') as $k => $label)<option value="{{ $k }}" @selected($service === $k)>{{ $label }}</option>@endforeach</select>
    </div>
    @if (! empty($withItem))
        <div class="field"><label for="{{ $p }}-item">{{ __('site.quickquote.item') }}</label><input class="input" id="{{ $p }}-item" name="about" maxlength="120" value="{{ $item ?? '' }}"></div>
    @endif
    <div class="row2">
        <div class="field"><label for="{{ $p }}-name">{{ __('site.quote.name') }}</label><input class="input" id="{{ $p }}-name" name="name" autocomplete="name" maxlength="120" required></div>
        <div class="field"><label for="{{ $p }}-phone">{{ __('site.quote.phone') }}</label><input class="input" id="{{ $p }}-phone" name="phone" type="tel" autocomplete="tel" placeholder="{{ __('site.quote.phone_ph') }}" maxlength="30" required dir="ltr"></div>
    </div>
    <div class="row2">
        <div class="field"><label for="{{ $p }}-email">{{ __('site.quote.email') }}</label><input class="input" id="{{ $p }}-email" name="email" type="email" autocomplete="email" maxlength="255" dir="ltr"></div>
        <div class="field"><label for="{{ $p }}-date">{{ __('site.contact.date') }}</label><input class="input" id="{{ $p }}-date" name="date" type="date" min="{{ now()->toDateString() }}"></div>
    </div>
    <div class="field"><label for="{{ $p }}-msg">{{ __('site.contact.message') }}</label><textarea class="textarea" id="{{ $p }}-msg" name="message" rows="3" maxlength="1800"></textarea></div>
    <input type="hidden" name="kind" value="contact">
    <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
    <p class="err-msg" data-form-error hidden></p>
    <button class="btn btn-red btn-l" type="submit" data-send>{{ $send ?? __('site.quickquote.send') }}</button>
    <p class="est-note">{{ __('site.quote.privacy') }}</p>
</div>
<div data-enq-done hidden class="bk-success"><div class="ico"><svg class="i i-l"><use href="#i-check"/></svg></div><h3>{{ __('site.quote.success_title') }}</h3><p class="muted">{{ __('site.contact.success') }}</p><span class="bk-ref" data-ref></span></div>
