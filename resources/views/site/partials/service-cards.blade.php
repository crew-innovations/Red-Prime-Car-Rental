@php use App\Support\Site\Site; @endphp
<div class="svc-grid">
    @foreach ($services as $slug => $svc)
        <a class="svc rv tilt" href="{{ Site::route('service', ['slug' => $slug]) }}">
            <span class="svc-img"><img src="{{ Site::img($svc['image']) }}" alt="" loading="lazy"></span>
            <span class="svc-body">
                <span class="svc-ico"><svg class="i"><use href="#i-{{ $svc['icon'] }}"/></svg></span>
                <b>{{ __('site.services.items.'.$slug.'.title') }}</b>
                <span class="muted">{{ __('site.services.items.'.$slug.'.lead') }}</span>
                <span class="link-more">{{ __('site.services.more') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></span>
            </span>
        </a>
    @endforeach
</div>
