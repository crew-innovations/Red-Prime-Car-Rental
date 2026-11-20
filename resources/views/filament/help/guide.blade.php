{{-- 20 Nov (item 12): one help guide (App\Support\HelpGuides). Used by the "?" panel and the Help centre. --}}
@php($first = true)
<div class="rp-guide">
    <p class="rp-guide-for">{{ $guide['for'] }}</p>

    @if (! empty($guide['short']))
        <div class="rp-guide-short">
            <strong>In short</strong>
            <ul>@foreach ($guide['short'] as $line)<li>{{ $line }}</li>@endforeach</ul>
        </div>
    @endif

    <dl class="rp-guide-meta">
        @if (! empty($guide['where']))<div><dt>Where</dt><dd>{{ $guide['where'] }}</dd></div>@endif
        @if (! empty($guide['who']))<div><dt>Who can use it</dt><dd>{{ $guide['who'] }}@if (empty($portal)) <span class="rp-guide-muted">(Admin can change what each role may do in Business settings › Roles &amp; permissions.)</span>@endif</dd></div>@endif
    </dl>

    @if (! empty($guide['steps']))
        <h3 class="rp-guide-h">How to</h3>
        <div class="rp-guide-steps">
            @foreach ($guide['steps'] as $title => $steps)
                <details class="rp-guide-step" @if (($guide['focus'] ?? null) === $title || ($first && empty($guide['focus']))) open @endif>
                    <summary>{{ $title }}</summary>
                    <ol>@foreach ($steps as $step)<li>{{ $step }}</li>@endforeach</ol>
                </details>
                @php($first = false)
            @endforeach
        </div>
    @endif

    @if (! empty($guide['buttons']))
        <h3 class="rp-guide-h">Buttons, tabs and fields</h3>
        <dl class="rp-guide-buttons">
            @foreach ($guide['buttons'] as $name => $what)
                <div><dt>{{ $name }}</dt><dd>{{ $what }}</dd></div>
            @endforeach
        </dl>
    @endif

    @if (! empty($guide['after']))
        <h3 class="rp-guide-h">What happens next</h3>
        <ul class="rp-guide-list">@foreach ($guide['after'] as $line)<li>{{ $line }}</li>@endforeach</ul>
    @endif

    @if (! empty($guide['tips']))
        <h3 class="rp-guide-h">Tips</h3>
        <ul class="rp-guide-list">@foreach ($guide['tips'] as $line)<li>{{ $line }}</li>@endforeach</ul>
    @endif
</div>
