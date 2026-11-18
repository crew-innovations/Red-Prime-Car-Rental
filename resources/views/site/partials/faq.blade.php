<div class="faq">
    @foreach ($faqs as $i => $f)
        <details class="rv" @if ($i === 0 && ($openFirst ?? true)) open @endif>
            <summary>{{ $f['q'] }}<span class="pm" aria-hidden="true"><svg class="i i-s"><use href="#i-plus"/></svg></span></summary>
            <div class="ans">{{ $f['a'] }}</div>
        </details>
    @endforeach
</div>
