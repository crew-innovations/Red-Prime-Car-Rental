<x-filament-panels::page>
    <div class="rp-pc-grid">
        <div>
            {{ $this->form }}
        </div>
        <div class="rp-stack">
            @php($count = $this->audienceCount())
            @php($plan = $this->plan())
            <x-filament::section icon="heroicon-o-user-group" heading="{{ $count }} {{ \Illuminate\Support\Str::plural('person', $count) }}">
                @if ($count === 0)
                    <p class="rp-muted">No one in this group has an email address{{ ($data['audience'] ?? '') === 'customers' ? ' and agreed to announcements' : '' }}.</p>
                @else
                    <p class="rp-muted">{{ implode(', ', $this->sample()) }}{{ $count > 6 ? ' and '.($count - 6).' more' : '' }}.</p>
                @endif
                <p class="rp-muted" style="margin-top:.5rem">Sent from {{ $plan['account'] }}, up to {{ $plan['per_hour'] }} an hour.
                    @if ($count > $plan['per_hour']) This group takes about {{ (int) ceil($count / $plan['per_hour']) }} hours. @endif
                </p>
            </x-filament::section>

            @if ($p = $this->preview())
                <x-filament::section icon="heroicon-o-eye" heading="Preview">
                    <div dir="{{ $p['rtl'] ? 'rtl' : 'ltr' }}">
                        <p><strong>{{ $p['subject'] }}</strong></p>
                        @foreach (preg_split('/\R{2,}/u', trim($p['body'])) as $para)
                            <p style="margin-top:.6rem">{!! nl2br(e($para)) !!}</p>
                        @endforeach
                        @if (($data['audience'] ?? '') === 'customers')
                            <p class="rp-muted" style="margin-top:.6rem;font-size:.8rem">You get this because you agreed to hear from us. <u>Unsubscribe</u>.</p>
                        @endif
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section icon="heroicon-o-clock" heading="Recent announcements">
                @forelse ($this->batches() as $b)
                    <div style="padding:.5rem 0;border-bottom:1px solid rgba(127,127,127,.15)">
                        <div><strong>{{ $b->subject }}</strong></div>
                        <div class="rp-muted" style="font-size:.85rem">
                            {{ \Illuminate\Support\Carbon::parse($b->created_at)->format('d M, H:i') }} · {{ $b->total }} people ·
                            {{ $b->sent }} sent @if ($b->sending) · {{ $b->sending }} sending @endif @if ($b->waiting) · {{ $b->waiting }} waiting @endif
                            @if ($b->failed) · <span style="color:#d7141a">{{ $b->failed }} failed</span> @endif @if ($b->cancelled) · {{ $b->cancelled }} not sent @endif
                        </div>
                        @if ($b->waiting)
                            <div style="display:flex;gap:.5rem;margin-top:.35rem">
                                <x-filament::button size="xs" color="gray" wire:click="sendNext('{{ $b->batch }}')">Send the next part now</x-filament::button>
                                <x-filament::button size="xs" color="danger" outlined wire:click="stop('{{ $b->batch }}')" wire:confirm="Stop the emails that have not gone out yet?">Stop</x-filament::button>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="rp-muted">None yet.</p>
                @endforelse
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
