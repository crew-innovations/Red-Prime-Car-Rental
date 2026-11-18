{{-- 5 Nov (S3, B.6): what each agent did in the period. Every number opens the list behind it. --}}
<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">From<br><input type="date" wire:model.live="from" class="rp-date-input"></label>
        <label class="text-sm">To<br><input type="date" wire:model.live="until" class="rp-date-input"></label>
        <div class="flex flex-wrap gap-2">
            @foreach (['month' => 'This month', 'last_month' => 'Last month', 'last_30' => 'Last 30 days', 'year' => 'This year'] as $k => $label)
                <x-filament::button size="sm" color="gray" wire:click="setRange('{{ $k }}')">{{ $label }}</x-filament::button>
            @endforeach
        </div>
    </div>

    @if (empty($rows))
        <x-filament::section>
            <p class="text-sm opacity-70">No agents yet. Add one under Settings › Team & roles with the role "Agent".</p>
        </x-filament::section>
    @else
        <div class="rp-agents-grid">
            @foreach ($rows as $i => $r)
                <x-filament::section>
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <div class="font-semibold">{{ $i === 0 && $r['revenue'] > 0 ? '🏆 ' : '' }}{{ $r['user']->name }}</div>
                            <div class="text-xs opacity-60">{{ $r['user']->is_active ? 'Active' : 'Switched off' }}</div>
                        </div>
                        <a class="text-sm font-semibold text-primary-600" href="{{ \App\Filament\Pages\Agents::leadsUrl($r['user']->id) }}">Open leads →</a>
                    </div>
                    <dl class="rp-agent-stats">
                        <div><dt>Leads accepted</dt><dd><a href="{{ \App\Filament\Pages\Agents::leadsUrl($r['user']->id) }}">{{ $r['leads'] }}</a></dd></div>
                        <div><dt>Time to accept</dt><dd>{{ $r['avg_minutes'] === null ? '–' : $r['avg_minutes'].' min' }}</dd></div>
                        <div><dt>Quotes sent</dt><dd>{{ $r['quotes'] }}</dd></div>
                        <div><dt>Bookings</dt><dd><a href="{{ \App\Filament\Pages\Agents::bookingsUrl($r['user']->id) }}">{{ $r['bookings'] }}</a></dd></div>
                        <div><dt>Conversion</dt><dd>{{ $r['conversion'] === null ? '–' : $r['conversion'].'%' }}</dd></div>
                        <div><dt>Revenue</dt><dd>AED {{ number_format($r['revenue']) }}</dd></div>
                        <div><dt>Collected</dt><dd>AED {{ number_format($r['collected']) }}</dd></div>
                        <div><dt>Still to collect</dt><dd><a href="{{ \App\Filament\Pages\Agents::bookingsUrl($r['user']->id, 'balance') }}">AED {{ number_format($r['to_collect']) }}</a></dd></div>
                        <div><dt>Discounts given</dt><dd>AED {{ number_format($r['discounts']) }}</dd></div>
                        <div><dt>Late follow-ups</dt><dd class="{{ $r['late'] > 0 ? 'text-danger-600' : '' }}"><a href="{{ \App\Filament\Pages\Agents::leadsUrl($r['user']->id, 'follow_up') }}">{{ $r['late'] }}</a></dd></div>
                    </dl>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
