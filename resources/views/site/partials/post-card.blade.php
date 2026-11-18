{{-- Blog post card. $post = BlogPost --}}
@php use App\Support\Site\Site; $url = Site::route('post', ['slug' => $post->slug]); @endphp
<article class="post-card rv tilt">
    <a class="post-img" href="{{ $url }}" tabindex="-1" aria-hidden="true"><img src="{{ \App\Http\Controllers\Website\SiteController::postImage($post) }}" alt="" loading="lazy"></a>
    <div class="post-body">
        <span class="card-tag">{{ $post->published_at?->locale(Site::locale())->translatedFormat('j M Y') }} · {{ __('site.blog.minutes', ['n' => $post->readMinutes()]) }}</span>
        <h3><a href="{{ $url }}">{{ $post->local('title') }}</a></h3>
        @if ($post->local('excerpt'))<p class="muted">{{ $post->local('excerpt') }}</p>@endif
        <a class="link-more" href="{{ $url }}">{{ __('site.blog.read') }} <svg class="i i-s flip"><use href="#i-arrow"/></svg></a>
    </div>
</article>
