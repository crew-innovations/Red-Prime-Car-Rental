@php use App\Support\Site\Site; $wa = Site::whatsapp($waText ?? __('site.wa.general')); @endphp
<section class="sec" style="padding-top:0">
    <div class="wrap">
        <div class="band rv">
            <div class="band-in">
                <div>
                    <span class="eyebrow">{{ Site::company() }}</span>
                    <h2 class="mt-1">{{ __('site.home.cta_title') }}</h2>
                    <p>{{ __('site.home.cta_text') }}</p>
                </div>
                <div class="band-actions">
                    @if ($wa)<a class="btn btn-wa btn-l" href="{{ $wa }}" target="_blank" rel="noopener"><svg class="i"><use href="#i-whatsapp"/></svg>{{ __('site.nav.whatsapp') }}</a>@endif
                    @if (Site::tel())<a class="btn btn-ghost btn-l" href="{{ Site::tel() }}"><svg class="i"><use href="#i-phone"/></svg><span class="ltr">{{ Site::phone() }}</span></a>@endif
                </div>
            </div>
        </div>
    </div>
</section>
