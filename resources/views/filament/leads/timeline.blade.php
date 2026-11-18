{{-- 5 Nov (S3, B.6): the lead's timeline, newest first. --}}
<ol class="rp-timeline">
    @forelse ($events as $e)
        <li class="rp-tl-item rp-tl-{{ $e['color'] }}">
            <span class="rp-tl-dot"><x-filament::icon :icon="$e['icon']" class="h-4 w-4" /></span>
            <div class="rp-tl-body">
                <div class="rp-tl-title">{{ $e['title'] }}</div>
                @if ($e['detail'])<div class="rp-tl-detail">{{ $e['detail'] }}</div>@endif
                <div class="rp-tl-time" title="{{ $e['at']->format('d M Y H:i') }}">{{ $e['at']->format('d M, H:i') }} · {{ $e['at']->diffForHumans() }}</div>
            </div>
        </li>
    @empty
        <li class="rp-tl-empty">Nothing yet.</li>
    @endforelse
</ol>
