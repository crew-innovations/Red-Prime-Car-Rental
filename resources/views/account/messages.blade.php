<x-filament-panels::page>
    @include('filament.chat.board', ['start' => new \Illuminate\Support\HtmlString(
        '<form wire:submit="start" class="rp-chat-start"><select wire:model="newFor" aria-label="About"><option value="">General question</option>'
        .$this->bookingsToAsk()->map(fn ($b) => '<option value="'.$b->id.'">Booking '.e($b->reference).' · '.e($b->start_at?->format('d M Y')).'</option>')->implode('')
        .'</select><button type="submit" class="rp-chat-new">New message</button></form>')])
</x-filament-panels::page>
