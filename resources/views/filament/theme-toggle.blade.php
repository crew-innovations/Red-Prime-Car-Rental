{{-- One-click light / dark switch. Uses Filament's own theme setting, so the user menu stays in sync. --}}
<button
    type="button"
    x-data="{ dark: document.documentElement.classList.contains('dark') }"
    x-on:theme-changed.window="$nextTick(() => dark = document.documentElement.classList.contains('dark'))"
    x-on:click="$dispatch('theme-changed', dark ? 'light' : 'dark')"
    x-bind:title="dark ? 'Switch to light theme' : 'Switch to dark theme'"
    x-bind:aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'"
    class="rp-theme-toggle"
>
    <x-filament::icon icon="heroicon-o-sun" class="h-5 w-5" x-show="dark" x-cloak />
    <x-filament::icon icon="heroicon-o-moon" class="h-5 w-5" x-show="! dark" x-cloak />
</button>
