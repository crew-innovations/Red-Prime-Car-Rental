{{-- 5 Nov: what each of the four roles can do (read-only). Driver works only from the phone screens. --}}
<div class="rp-role-table">
    <div class="rp-role-cards">
        @foreach ($roles as $role => $about)
            <div class="rp-role-card"><strong>{{ $role }}</strong><span>{{ $about }}</span></div>
        @endforeach
    </div>
    <div style="overflow-x:auto">
        <table class="rp-simple-table">
            <thead>
                <tr><th style="text-align:left">Can…</th>@foreach (\App\Support\Access::ROLES as $r)<th>{{ $r }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        @foreach (\App\Support\Access::ROLES as $r)
                            <td style="text-align:center">{!! in_array($r, $row['roles'], true) ? '<span class="rp-yes" aria-label="yes">✓</span>' : '<span class="rp-no" aria-label="no">–</span>' !!}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="rp-role-note">Agents only see their own leads and everything that comes from them. Drivers only see their own jobs on the phone, never money amounts.</p>
</div>
