@php
    $user = auth()->user();
    $initials = collect(explode(' ', trim((string) $user?->name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    $role = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->first() : null;
@endphp

@if ($user)
    <div class="rp-sidefoot" x-bind:class="{ 'is-collapsed': ! $store.sidebar.isOpen }">
        <span class="rp-sidefoot-avatar" aria-hidden="true">{{ $initials ?: '?' }}</span>
        <span class="rp-sidefoot-text" x-show="$store.sidebar.isOpen">
            <span class="rp-sidefoot-name">{{ $user->name }}</span>
            @if ($role)
                <span class="rp-sidefoot-role">{{ $role }}</span>
            @endif
        </span>
    </div>
@endif
