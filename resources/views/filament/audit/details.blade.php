{{-- 19 Nov: one audit log line in full (Audit log › Open). --}}
<div style="display:grid;gap:1rem;font-size:.875rem">
    <dl style="display:grid;grid-template-columns:max-content 1fr;gap:.35rem 1rem;margin:0">
        <dt style="opacity:.65">When</dt><dd style="margin:0">{{ $log->created_at?->format('d M Y, H:i:s') }} <span style="opacity:.65">({{ $log->created_at?->diffForHumans() }})</span></dd>
        <dt style="opacity:.65">Who</dt><dd style="margin:0">{{ $log->user?->name ?? 'System / website' }}@if ($log->role) <span style="opacity:.65">· {{ $log->role }}</span>@endif @if ($log->user?->email) <span style="opacity:.65">· {{ $log->user->email }}</span>@endif</dd>
        <dt style="opacity:.65">Action</dt><dd style="margin:0">{{ \App\Models\AuditLog::EVENTS[$log->event] ?? ucfirst(str_replace('_', ' ', $log->event)) }}@if ($log->outcome) · <b>{{ $log->outcome === 'denied' ? 'Refused' : ucfirst($log->outcome) }}</b>@endif</dd>
        <dt style="opacity:.65">Module</dt><dd style="margin:0">{{ \App\Support\Audit\AuditView::module($log) }}</dd>
        @if ($log->auditable_type)
            <dt style="opacity:.65">Record</dt><dd style="margin:0">@if ($url)<a href="{{ $url }}" style="text-decoration:underline">{{ $record }}</a>@else{{ $record }}@endif</dd>
        @endif
        @if ($log->note)
            <dt style="opacity:.65">Note</dt><dd style="margin:0">{{ $log->note }}</dd>
        @endif
        @if ($log->ip_address)
            <dt style="opacity:.65">IP / device</dt><dd style="margin:0">{{ $log->ip_address }} <span style="opacity:.65">· {{ \Illuminate\Support\Str::limit((string) $log->user_agent, 90) }}</span></dd>
        @endif
        @if ($sameClick)
            <dt style="opacity:.65">Same click</dt><dd style="margin:0">{{ $sameClick }} other line(s) were saved by the same action (search the request id {{ \Illuminate\Support\Str::limit((string) $log->request_id, 8, '') }}).</dd>
        @endif
    </dl>

    @if ($changes)
        <table style="width:100%;border-collapse:collapse">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid rgba(127,127,127,.3)">
                    <th style="padding:.4rem .5rem .4rem 0">Field</th>
                    @if ($log->event !== 'created')<th style="padding:.4rem .5rem">Before</th>@endif
                    <th style="padding:.4rem 0 .4rem .5rem">{{ $log->event === 'created' ? 'Value' : 'After' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($changes as [$field, $old, $new])
                    <tr style="border-bottom:1px solid rgba(127,127,127,.15);vertical-align:top">
                        <td style="padding:.4rem .5rem .4rem 0;font-weight:600">{{ $field }}</td>
                        @if ($log->event !== 'created')<td style="padding:.4rem .5rem;opacity:.75;word-break:break-word">{{ $old }}</td>@endif
                        <td style="padding:.4rem 0 .4rem .5rem;word-break:break-word">{{ $new }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif ($log->event === 'created')
        <p style="opacity:.7;margin:0">The record was created. Later changes show here field by field.</p>
    @endif
    <p style="opacity:.6;margin:0;font-size:.75rem">Audit log lines are saved once and cannot be changed or deleted from the CRM. Passwords, keys and card numbers are never stored.</p>
</div>
