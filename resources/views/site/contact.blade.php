@extends('site.layout')
@php use App\Support\Site\Site; $wa = Site::whatsapp(__('site.wa.general')); $nets = ['instagram' => ['Instagram', 'ig'], 'facebook' => ['Facebook', 'fb'], 'tiktok' => ['TikTok', 'tt'], 'snapchat' => ['Snapchat', 'sc']]; @endphp
@section('content')
<section class="phead">
    <div class="wrap">
        <nav class="crumbs" aria-label="{{ __('site.common.breadcrumb') }}"><a href="{{ Site::route('home') }}">{{ __('site.common.home') }}</a><svg class="i flip"><use href="#i-chev-r"/></svg><span>{{ __('site.nav.contact') }}</span></nav>
        <span class="eyebrow mt-2">{{ __('site.contact.eyebrow') }}</span>
        <h1 data-split>{{ __('site.contact.title') }}</h1>
        <p>{{ __('site.contact.lead') }}</p>
    </div>
</section>

<section class="sec" style="padding-top:36px">
    <div class="wrap grid" style="gap:28px">
        <div class="contact-cards">
            @if (Site::tel())<a class="ccard rv" href="{{ Site::tel() }}"><span class="ico"><svg class="i"><use href="#i-phone"/></svg></span><span><small>{{ __('site.contact.phone') }}</small><b class="ltr">{{ Site::phone() }}</b></span></a>@endif
            @if ($wa)<a class="ccard rv" href="{{ $wa }}" target="_blank" rel="noopener"><span class="ico" style="background:#1fa855;color:#fff"><svg class="i"><use href="#i-whatsapp"/></svg></span><span><small>{{ __('site.contact.whatsapp') }}</small><b class="ltr">{{ \App\Models\Setting::read('website_whatsapp') ?: Site::phone() }}</b></span></a>@endif
            @if (Site::email())<a class="ccard rv" href="mailto:{{ Site::email() }}"><span class="ico"><svg class="i"><use href="#i-mail"/></svg></span><span><small>{{ __('site.contact.email') }}</small><b>{{ Site::email() }}</b></span></a>@endif
            @if (Site::mapUrl())<a class="ccard rv" href="{{ Site::mapUrl() }}" target="_blank" rel="noopener"><span class="ico"><svg class="i"><use href="#i-pin"/></svg></span><span><small>{{ __('site.contact.location') }}</small><b>{{ Site::address() ?: __('site.footer.city') }}</b><small class="gold">{{ __('site.contact.open_map') }} →</small></span></a>@endif
        </div>

        <div class="split" style="align-items:start">
            <form class="panel rv" data-enquiry novalidate>
                <h2 style="font-size:24px">{{ __('site.contact.form_title') }}</h2>
                <div data-enq-form class="grid" style="gap:16px">
                    <div class="field"><label for="c-service">{{ __('site.contact.service') }}</label>
                        <select class="select" id="c-service" name="service">@foreach (__('site.contact.services') as $k => $label)<option value="{{ $k }}" @selected(request('service') === $k)>{{ $label }}</option>@endforeach</select>
                    </div>
                    <div class="row2">
                        <div class="field"><label for="c-name">{{ __('site.quote.name') }}</label><input class="input" id="c-name" name="name" autocomplete="name" maxlength="120" required></div>
                        <div class="field"><label for="c-phone">{{ __('site.quote.phone') }}</label><input class="input" id="c-phone" name="phone" type="tel" autocomplete="tel" placeholder="{{ __('site.quote.phone_ph') }}" maxlength="30" required dir="ltr"></div>
                    </div>
                    <div class="row2">
                        <div class="field"><label for="c-email">{{ __('site.quote.email') }}</label><input class="input" id="c-email" name="email" type="email" autocomplete="email" maxlength="255" dir="ltr"></div>
                        <div class="field"><label for="c-date">{{ __('site.contact.date') }}</label><input class="input" id="c-date" name="date" type="date" min="{{ now()->toDateString() }}"></div>
                    </div>
                    <div class="field"><label for="c-msg">{{ __('site.contact.message') }}</label><textarea class="textarea" id="c-msg" name="message" rows="5" maxlength="2000"></textarea></div>
                    <input type="hidden" name="kind" value="contact">
                    <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
                    <p class="err-msg" data-form-error hidden></p>
                    <button class="btn btn-red btn-l" type="submit" data-send>{{ __('site.contact.send') }}</button>
                    <p class="est-note">{{ __('site.quote.privacy') }}</p>
                </div>
                <div data-enq-done hidden class="bk-success"><div class="ico"><svg class="i i-l"><use href="#i-check"/></svg></div><h3>{{ __('site.quote.success_title') }}</h3><p class="muted">{{ __('site.contact.success') }}</p><span class="bk-ref" data-ref></span></div>
            </form>

            <div class="grid" style="gap:16px">
                <form class="panel rv" data-enquiry novalidate>
                    <h2 style="font-size:22px">{{ __('site.contact.callback_title') }}</h2>
                    <p class="muted">{{ __('site.contact.callback_text') }}</p>
                    <div data-enq-form class="grid" style="gap:12px">
                        <div class="field"><label for="cb-name">{{ __('site.quote.name') }}</label><input class="input" id="cb-name" name="name" autocomplete="name" maxlength="120" required></div>
                        <div class="field"><label for="cb-phone">{{ __('site.quote.phone') }}</label><input class="input" id="cb-phone" name="phone" type="tel" autocomplete="tel" maxlength="30" required dir="ltr" placeholder="{{ __('site.quote.phone_ph') }}"></div>
                        <input type="hidden" name="kind" value="callback"><input type="hidden" name="service" value="other">
                        <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
                        <p class="err-msg" data-form-error hidden></p>
                        <button class="btn btn-gold" type="submit" data-send><svg class="i"><use href="#i-phone"/></svg>{{ __('site.contact.callback_send') }}</button>
                    </div>
                    <div data-enq-done hidden><p class="badge badge-ok" style="font-size:14px">{{ __('site.contact.success') }}</p></div>
                </form>
                @if (Site::socials())
                    <div class="panel rv">
                        <h2 style="font-size:22px">{{ __('site.contact.follow') }}</h2>
                        <div class="grid" style="gap:10px">
                            @foreach (Site::socials() as $net => $url)
                                <a class="social" style="padding:14px" href="{{ $url }}" target="_blank" rel="noopener"><span class="ico {{ $nets[$net][1] }}" style="width:44px;height:44px"><svg class="i"><use href="#i-{{ $net }}"/></svg></span><span><b>{{ $nets[$net][0] }}</b><small>@redprimerentals</small></span></a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
@endsection
