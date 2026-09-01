@props(['phase', 'matches'])

{{--
    Animación puramente client-side (Alpine): todos los cruces ya están creados
    y cargados en $matches en el momento del render, así que no hay ninguna
    razón para depender de un componente Livewire con polling al servidor para
    ir "revelándolos" -- eso fue justamente lo que se quedó pegado en
    "Sorteando..." la primera vez (el wire:poll nunca llegaba a incrementar el
    contador). Un setInterval en el navegador es más simple y no depende de
    ninguna petición de red para funcionar.
--}}
<div
    x-data="{
        revealed: 0,
        total: {{ $matches->count() }},
        dismissed: false,
        init() {
            if (this.total === 0) { this.dismissed = true; return; }

            const timer = setInterval(() => {
                if (this.revealed < this.total) {
                    this.revealed++;
                } else {
                    clearInterval(timer);
                }
            }, 900);
        },
    }"
    x-show="!dismissed"
    x-cloak
    class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-8 overflow-y-auto bg-zinc-950/90 p-6 backdrop-blur-xl"
>
    <div class="animate-fade-in-up space-y-2 text-center">
        <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-xs font-semibold uppercase tracking-widest text-white/70">
            <flux:icon.sparkles variant="micro" class="size-3.5 text-accent-content animate-shimmer-pulse" />
            {{ __('Sorteo en vivo') }}
        </div>

        <flux:heading size="xl" class="text-3xl! text-white">{{ $phase->name }}</flux:heading>

        <flux:text class="text-white/60">
            <span x-text="Math.min(revealed, total)"></span> {{ __('de') }} {{ $matches->count() }} {{ __('cruces revelados') }}
        </flux:text>
    </div>

    <div class="grid w-full max-w-lg gap-3">
        @foreach ($matches as $index => $match)
            <div>
                <div x-show="revealed > {{ $index }}" x-cloak class="animate-reveal-pop flex items-center justify-between gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 glass-panel">
                    <div class="min-w-0 flex-1 text-right text-sm font-semibold truncate text-white">
                        {{ $match->homeTeam->name }}
                    </div>

                    <div class="shrink-0 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-accent-content">
                        {{ __('vs') }}
                    </div>

                    <div class="min-w-0 flex-1 text-left text-sm font-semibold truncate text-white">
                        {{ $match->awayTeam->name }}
                    </div>
                </div>

                <div x-show="revealed <= {{ $index }}" class="flex items-center justify-center gap-3 rounded-2xl border border-dashed border-white/15 bg-white/[0.03] p-4">
                    <div class="flex size-8 items-center justify-center rounded-full bg-white/10 text-sm font-bold text-white/40 animate-shimmer-pulse">?</div>
                    <div class="text-xs font-medium uppercase tracking-widest text-white/30">{{ __('Sorteando…') }}</div>
                    <div class="flex size-8 items-center justify-center rounded-full bg-white/10 text-sm font-bold text-white/40 animate-shimmer-pulse">?</div>
                </div>
            </div>
        @endforeach
    </div>

    <flux:button variant="primary" x-show="revealed >= total" x-cloak @click="dismissed = true" icon:trailing="arrow-right" class="animate-fade-in-up">
        {{ __('Ver fase') }}
    </flux:button>
</div>
