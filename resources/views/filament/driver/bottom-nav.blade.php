{{-- Driver phone: bottom navigation (4 Nov, 15). Shown on phones only; the sidebar stays for tablets and PCs. --}}
@php
    \App\Support\Drivers\DriverLang::apply(auth()->user());
    $items = [
        ['url' => \App\Filament\Pages\MyJobs::getUrl(), 'icon' => 'heroicon-o-truck', 'label' => __('driver.nav_jobs'), 'on' => request()->routeIs('filament.admin.pages.driver.jobs', 'filament.admin.pages.driver-job'), 'badge' => \App\Filament\Pages\MyJobs::getNavigationBadge()],
        ['url' => \App\Filament\Pages\MySchedule::getUrl(), 'icon' => 'heroicon-o-calendar-days', 'label' => __('driver.nav_schedule'), 'on' => request()->routeIs('filament.admin.pages.driver.schedule')],
        ['url' => \App\Filament\Pages\MyFuel::getUrl(), 'icon' => 'heroicon-o-beaker', 'label' => __('driver.nav_fuel'), 'on' => request()->routeIs('filament.admin.pages.driver.fuel')], // 5 Nov (S5)
        ['url' => \App\Filament\Pages\Messages::getUrl(), 'icon' => 'heroicon-o-chat-bubble-left-right', 'label' => __('driver.nav_messages'), 'on' => request()->routeIs('filament.admin.pages.messages')],
        ['url' => \App\Filament\Pages\MyProfile::getUrl(), 'icon' => 'heroicon-o-user-circle', 'label' => __('driver.nav_me'), 'on' => request()->routeIs('filament.admin.pages.my-profile', 'filament.admin.pages.my-notifications')],
    ];
@endphp
<nav class="rp-dv-bottomnav" aria-label="{{ __('driver.nav_jobs') }}">
    @foreach ($items as $i)
        <a href="{{ $i['url'] }}" class="{{ $i['on'] ? 'is-on' : '' }}" @if ($i['on']) aria-current="page" @endif>
            <span class="rp-dv-bn-icon"><x-filament::icon :icon="$i['icon']" class="h-6 w-6" />@if (! empty($i['badge']))<b>{{ $i['badge'] }}</b>@endif</span>
            <span>{{ $i['label'] }}</span>
        </a>
    @endforeach
</nav>
