{{-- Customer signature on the driver's phone (4 Nov, 15): a finger on a canvas, saved as a PNG. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
            state: $wire.$entangle('{{ $getStatePath() }}'),
            drawing: false, empty: true, ctx: null,
            init() {
                const c = this.$refs.pad; const r = window.devicePixelRatio || 1;
                const w = c.offsetWidth || 320, h = 180;
                c.width = w * r; c.height = h * r; c.style.height = h + 'px';
                this.ctx = c.getContext('2d'); this.ctx.scale(r, r);
                this.ctx.lineWidth = 2.4; this.ctx.lineCap = 'round'; this.ctx.lineJoin = 'round'; this.ctx.strokeStyle = '#111';
                this.ctx.fillStyle = '#fff'; this.ctx.fillRect(0, 0, w, h);
            },
            pos(e) { const b = this.$refs.pad.getBoundingClientRect(); const p = e.touches ? e.touches[0] : e; return [p.clientX - b.left, p.clientY - b.top]; },
            start(e) { this.drawing = true; const [x, y] = this.pos(e); this.ctx.beginPath(); this.ctx.moveTo(x, y); },
            move(e) { if (!this.drawing) return; const [x, y] = this.pos(e); this.ctx.lineTo(x, y); this.ctx.stroke(); this.empty = false; },
            end() { if (!this.drawing) return; this.drawing = false; if (!this.empty) this.state = this.$refs.pad.toDataURL('image/png'); },
            clear() { const c = this.$refs.pad; this.ctx.fillStyle = '#fff'; this.ctx.fillRect(0, 0, c.width, c.height); this.empty = true; this.state = null; },
        }" class="rp-sign">
        <canvas x-ref="pad" class="rp-sign-pad"
            x-on:mousedown.prevent="start($event)" x-on:mousemove.prevent="move($event)" x-on:mouseup="end()" x-on:mouseleave="end()"
            x-on:touchstart.prevent="start($event)" x-on:touchmove.prevent="move($event)" x-on:touchend="end()"></canvas>
        <div class="rp-sign-bar">
            <span class="rp-dv-meta" x-show="empty">{{ __('driver.sign_here') }}</span>
            <span class="rp-dv-meta" x-show="!empty" x-cloak>✓ {{ __('driver.signed') }}</span>
            <button type="button" class="rp-dv-link" x-on:click="clear()">{{ __('driver.clear') }}</button>
        </div>
    </div>
</x-dynamic-component>
