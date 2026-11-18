<x-filament-panels::page>
    <div class="rp-driver" dir="{{ \App\Support\Drivers\DriverLang::dir() }}">
        @php($mine = $this->myOffTime())
        @if ($mine->isNotEmpty())
            <div class="rp-dv-section">
                <div class="rp-dv-label">{{ __('driver.not_available') }}</div>
                @foreach ($mine as $o)
                    <div class="rp-dv-docrow" wire:key="off-{{ $o->id }}">
                        <div><div class="rp-dv-value">{{ $o->reason ? __('driver.off_'.\Illuminate\Support\Str::snake($o->reason)) : __('driver.not_available') }}</div>
                            <div class="rp-dv-meta" dir="ltr">{{ $o->starts_at->translatedFormat('D d M H:i') }} – {{ $o->ends_at->translatedFormat($o->starts_at->isSameDay($o->ends_at) ? 'H:i' : 'D d M H:i') }}</div></div>
                        <button type="button" class="rp-dv-link" wire:click="removeOff({{ $o->id }})" wire:confirm="{{ __('driver.remove_confirm') }}">{{ __('driver.remove') }}</button>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="rp-dv-days">
            @foreach ($this->days() as $day)
                <div class="rp-dv-day {{ $day['date']->isToday() ? 'is-today' : '' }} {{ $day['off']->isNotEmpty() ? 'is-off' : '' }}">
                    <div class="rp-dv-day-head">
                        <span>{{ $day['date']->isToday() ? __('driver.today') : ($day['date']->isTomorrow() ? __('driver.tomorrow') : $day['date']->translatedFormat('l')) }}</span>
                        <small>{{ $day['date']->translatedFormat('d M') }}</small>
                    </div>
                    @foreach ($day['off'] as $o)
                        <div class="rp-dv-day-off">{{ $o->reason ? __('driver.off_'.\Illuminate\Support\Str::snake($o->reason)) : __('driver.not_available') }} · <span dir="ltr">{{ $o->starts_at->isSameDay($day['date']) ? $o->starts_at->format('H:i') : '00:00' }}–{{ $o->ends_at->isSameDay($day['date']) ? $o->ends_at->format('H:i') : '24:00' }}</span></div>
                    @endforeach
                    @forelse ($day['jobs'] as $job)
                        <a class="rp-dv-day-job" href="{{ \App\Filament\Pages\DriverJob::getUrl(['job' => $job->id]) }}">
                            <b dir="ltr">{{ $job->scheduled_at->format('H:i') }}</b>
                            <span class="rp-dv-badge rp-dv-{{ $job->type }}">{{ __('driver.type_'.$job->typeKey()) }}</span>
                            <span class="rp-dv-day-what">{{ $job->booking?->bookable?->name }}</span>
                        </a>
                    @empty
                        @if ($day['off']->isEmpty())<div class="rp-dv-meta">{{ __('driver.free') }}</div>@endif
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
