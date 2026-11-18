{{-- Quick quote popup, on every page. Every request becomes a lead in the CRM. --}}
<div class="modal" data-quote-modal role="dialog" aria-modal="true" aria-labelledby="qq-title" hidden>
    <div class="modal-bg" data-close-quote></div>
    <form class="modal-panel" data-enquiry novalidate>
        <button type="button" class="tool modal-x" data-close-quote aria-label="{{ __('site.common.close') }}"><svg class="i"><use href="#i-x"/></svg></button>
        <span class="eyebrow">{{ \App\Support\Site\Site::company() }}</span>
        <h2 id="qq-title">{{ __('site.quickquote.title') }}</h2>
        <p class="muted">{{ __('site.quickquote.lead') }}</p>
        @include('site.partials.enquiry-fields', ['p' => 'qq', 'withItem' => true, 'item' => '', 'service' => 'car', 'send' => null])
    </form>
</div>
