{{-- 19 Nov (suggestion 5): tap the damaged areas on a top view of the car. State = list of area keys. --}}
@php($zones = \App\Support\Contracts\CarZones::ZONES)
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}'),
            has(z) { return Array.isArray(this.state) && this.state.includes(z) },
            toggle(z) { let s = Array.isArray(this.state) ? [...this.state] : []; s = s.includes(z) ? s.filter(x => x !== z) : [...s, z]; this.state = s } }"
         style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start">
        <svg viewBox="0 0 220 420" style="width:180px;height:auto;flex:none" xmlns="http://www.w3.org/2000/svg">
            <style>.z{fill:rgba(127,127,127,.12);stroke:rgba(127,127,127,.7);stroke-width:1.5;cursor:pointer}.z:hover{fill:rgba(215,20,26,.25)}.on{fill:rgba(215,20,26,.65)!important;stroke:#d7141a}</style>
            <rect class="z" :class="has('front_bumper') && 'on'" x="45" y="10" width="130" height="22" rx="10" @click="toggle('front_bumper')"><title>Front bumper</title></rect>
            <rect class="z" :class="has('bonnet') && 'on'" x="45" y="34" width="130" height="70" rx="8" @click="toggle('bonnet')"><title>Bonnet</title></rect>
            <rect class="z" :class="has('windscreen') && 'on'" x="52" y="106" width="116" height="34" rx="6" @click="toggle('windscreen')"><title>Windscreen</title></rect>
            <rect class="z" :class="has('roof') && 'on'" x="55" y="142" width="110" height="120" rx="6" @click="toggle('roof')"><title>Roof</title></rect>
            <rect class="z" :class="has('rear_window') && 'on'" x="52" y="264" width="116" height="32" rx="6" @click="toggle('rear_window')"><title>Rear window</title></rect>
            <rect class="z" :class="has('boot') && 'on'" x="45" y="298" width="130" height="78" rx="8" @click="toggle('boot')"><title>Boot</title></rect>
            <rect class="z" :class="has('rear_bumper') && 'on'" x="45" y="378" width="130" height="22" rx="10" @click="toggle('rear_bumper')"><title>Rear bumper</title></rect>
            <rect class="z" :class="has('front_left') && 'on'" x="20" y="104" width="23" height="100" rx="5" @click="toggle('front_left')"><title>Front left</title></rect>
            <rect class="z" :class="has('rear_left') && 'on'" x="20" y="206" width="23" height="100" rx="5" @click="toggle('rear_left')"><title>Rear left</title></rect>
            <rect class="z" :class="has('front_right') && 'on'" x="177" y="104" width="23" height="100" rx="5" @click="toggle('front_right')"><title>Front right</title></rect>
            <rect class="z" :class="has('rear_right') && 'on'" x="177" y="206" width="23" height="100" rx="5" @click="toggle('rear_right')"><title>Rear right</title></rect>
            <rect class="z" :class="has('wheel_fl') && 'on'" x="2" y="50" width="16" height="44" rx="5" @click="toggle('wheel_fl')"><title>Front left wheel</title></rect>
            <rect class="z" :class="has('wheel_fr') && 'on'" x="202" y="50" width="16" height="44" rx="5" @click="toggle('wheel_fr')"><title>Front right wheel</title></rect>
            <rect class="z" :class="has('wheel_rl') && 'on'" x="2" y="316" width="16" height="44" rx="5" @click="toggle('wheel_rl')"><title>Rear left wheel</title></rect>
            <rect class="z" :class="has('wheel_rr') && 'on'" x="202" y="316" width="16" height="44" rx="5" @click="toggle('wheel_rr')"><title>Rear right wheel</title></rect>
            <text x="110" y="416" text-anchor="middle" font-size="11" fill="currentColor" opacity=".6">rear</text>
            <text x="110" y="8" text-anchor="middle" font-size="9" fill="currentColor" opacity=".6">front</text>
        </svg>
        <div style="flex:1;min-width:160px;font-size:.85rem">
            <button type="button" @click="toggle('interior')" style="padding:.3rem .7rem;border-radius:999px;border:1px solid rgba(127,127,127,.5);margin-bottom:.5rem" :style="has('interior') && 'background:#d7141a;color:#fff;border-color:#d7141a'">Interior</button>
            <div style="opacity:.75">Tap every damaged area.</div>
            <ul style="margin-top:.4rem;list-style:disc;padding-left:1.1rem">
                @foreach ($zones as $key => $label)
                    <li x-show="has('{{ $key }}')" x-cloak>{{ $label }}</li>
                @endforeach
            </ul>
        </div>
    </div>
</x-dynamic-component>
