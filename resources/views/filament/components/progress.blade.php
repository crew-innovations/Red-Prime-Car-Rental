{{-- 6 Nov (S4 / C.2): where the sale stands. Fed by App\Support\Sales\NextStep (stores nothing). --}}
@if ($sub)<span class="rp-progress-sub">{{ $sub }}</span>@endif
<span class="rp-progress {{ $step['stopped'] ? 'rp-progress-stopped' : '' }}" role="list" aria-label="Progress">
    @foreach ($step['steps'] as $s)
        <span role="listitem" class="rp-progress-step {{ $s['done'] ? 'is-done' : '' }} {{ $s['current'] ? 'is-current' : '' }}" @if ($s['current']) aria-current="step" @endif>
            <span class="rp-progress-dot">@if ($s['done'])✓@endif</span>
            <span class="rp-progress-label">{{ $s['label'] }}</span>
        </span>
    @endforeach
    @if ($step['stopped'])<span class="rp-progress-stop">{{ $step['stopped'] }}</span>@endif
</span>
