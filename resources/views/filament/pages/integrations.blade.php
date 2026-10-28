@php
    $groups = $this->providers();
    $statusTone = ['connected' => 'ok', 'configured' => 'warn', 'error' => 'bad', 'expired' => 'bad', 'blocked' => 'warn', 'not_set_up' => 'off'];
@endphp
<x-filament-panels::page>
    <p class="rp-int-intro">Each service shows its real state. A service is only <b>Connected</b> after a successful test or sign-in. Saving keys is not enough.</p>

    @foreach ($groups as $group => $providers)
        <section class="rp-int-group">
            <h2>{{ $group }}</h2>
            <div class="rp-int-grid">
                @foreach ($providers as $p)
                    @php($i = $p['i'])
                    <article class="rp-int-card">
                        <header>
                            <div>
                                <h3>{{ $p['def']['label'] }}</h3>
                                <p>{{ $p['def']['description'] ?? '' }}</p>
                            </div>
                            <span class="rp-int-status is-{{ $statusTone[$p['status']] ?? 'off' }}">{{ \App\Models\Integration::STATUSES[$p['status']] ?? $p['status'] }}</span>
                        </header>

                        <dl class="rp-int-facts">
                            <div><dt>Switched on</dt><dd>{{ $i->enabled ? 'Yes' : 'No' }}</dd></div>
                            @if (! empty($p['def']['modes']))<div><dt>Mode</dt><dd>{{ $i->mode === 'live' ? 'Live' : 'Test / sandbox' }}</dd></div>@endif
                            @if ($i->account_label)<div><dt>Account</dt><dd>{{ $i->account_label }}</dd></div>@endif
                            <div><dt>Last test</dt><dd>{{ $i->last_tested_at?->format('d M Y H:i') ?? 'Never' }}</dd></div>
                            @if ($i->token_expires_at)<div><dt>Access expires</dt><dd>{{ $i->token_expires_at->format('d M Y') }}</dd></div>@endif
                            @if (! empty($p['def']['webhook']))<div><dt>Webhook address</dt><dd class="rp-int-mono">{{ url($p['def']['webhook']) }}</dd></div>@endif
                        </dl>

                        @if ($i->last_error)
                            <p class="rp-int-error"><b>Last error:</b> {{ $i->last_error }}</p>
                        @endif
                        @if ($p['missing'])
                            <p class="rp-int-missing"><b>Still needed:</b> {{ implode(', ', $p['missing']) }}</p>
                        @endif

                        <details class="rp-int-req">
                            <summary>What RED PRIME needs to arrange</summary>
                            <ul>@foreach ($p['def']['requirements'] ?? [] as $r)<li>{{ $r }}</li>@endforeach</ul>
                            @if (! empty($p['def']['docs']))<a href="{{ $p['def']['docs'] }}" target="_blank" rel="noopener">Official setup guide</a>@endif
                        </details>

                        @if ($p['events']->isNotEmpty())
                            <details class="rp-int-req">
                                <summary>Recent activity</summary>
                                <ul class="rp-int-events">
                                    @foreach ($p['events'] as $e)
                                        <li><span class="is-{{ $e->status === 'ok' ? 'ok' : ($e->status === 'duplicate' ? 'warn' : 'bad') }}">{{ ucfirst($e->status) }}</span> {{ $e->direction === 'in' ? 'Received' : 'Sent' }} {{ $e->event }} · {{ $e->created_at->format('d M H:i') }}@if ($e->error) · {{ \Illuminate\Support\Str::limit($e->error, 80) }}@endif</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        <footer>
                            {{ ($this->setUpAction)(['provider' => $p['key']]) }}
                            @if ($i->exists)
                                {{ ($this->testAction)(['provider' => $p['key']]) }}
                                {{ ($this->disconnectAction)(['provider' => $p['key']]) }}
                            @endif
                        </footer>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach

    <x-filament-actions::modals />
</x-filament-panels::page>
