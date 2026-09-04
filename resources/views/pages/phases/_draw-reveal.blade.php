@props(['phase', 'matches', 'tables' => []])

{{--
    Animación puramente client-side (Alpine): todos los cruces ya están creados
    y cargados en $matches en el momento del render, así que no hay ninguna
    razón para depender de un componente Livewire con polling al servidor para
    ir "revelándolos" -- eso fue justamente lo que se quedó pegado en
    "Sorteando..." la primera vez (el wire:poll nunca llegaba a incrementar el
    contador). Un setInterval en el navegador es más simple y no depende de
    ninguna petición de red para funcionar.

    Los equipos se revelan de a uno (no de a par), en el mismo orden en que
    quedaron guardados en $matches (home, away, home, away...) -- ese orden ya
    es el resultado del sorteo real (KnockoutBracketService::generateBracket
    baraja el pool antes de crear los partidos), así que no hay nada que
    volver a randomizar acá: solo animar la revelación en ese orden y marcar
    en las tablas de origen cada equipo a medida que "sale".
--}}
@php
    $positions = [];

    foreach ($matches as $index => $match) {
        $positions[$match->home_team_id] = $index * 2;
        $positions[$match->away_team_id] = $index * 2 + 1;
    }
@endphp
<div
    x-data="{
        revealed: 0,
        total: {{ count($positions) }},
        dismissed: false,
        positions: {{ \Illuminate\Support\Js::from($positions) }},
        init() {
            if (this.total === 0) { this.dismissed = true; return; }

            const timer = setInterval(() => {
                if (this.revealed < this.total) {
                    this.revealed++;
                } else {
                    clearInterval(timer);
                }
            }, 650);
        },
        isQualifier(teamId) {
            return this.positions[teamId] !== undefined;
        },
        isDrawn(teamId) {
            return this.isQualifier(teamId) && this.revealed > this.positions[teamId];
        },
    }"
    x-show="!dismissed"
    x-cloak
    class="fixed inset-0 z-50 flex flex-col items-center gap-8 overflow-y-auto bg-zinc-950/90 p-6 py-10 backdrop-blur-xl"
>
    <div class="animate-fade-in-up space-y-2 text-center">
        <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-xs font-semibold uppercase tracking-widest text-white/70">
            <flux:icon.sparkles variant="micro" class="size-3.5 text-accent-content animate-shimmer-pulse" />
            {{ __('Sorteo en vivo') }}
        </div>

        <flux:heading size="xl" class="text-3xl! text-white">{{ $phase->name }}</flux:heading>

        <flux:text class="text-white/60">
            <span x-text="Math.min(revealed, total)"></span> {{ __('de') }} {{ count($positions) }} {{ __('equipos sorteados') }}
        </flux:text>
    </div>

    <div class="grid w-full gap-6 {{ count($tables) > 0 ? 'max-w-5xl lg:grid-cols-2' : 'max-w-lg' }}">
        @if (count($tables) > 0)
            <div class="space-y-4">
                @foreach ($tables as $table)
                    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/5 glass-panel">
                        <div class="border-b border-white/10 px-4 py-2.5">
                            <flux:heading size="sm" class="text-white!">{{ $table['label'] }}</flux:heading>
                        </div>

                        <div class="divide-y divide-white/5">
                            @forelse ($table['rows'] as $index => $row)
                                @php $teamId = $row['team']->id; @endphp
                                <div
                                    class="flex items-center gap-3 px-4 py-2 text-sm transition-all duration-500"
                                    :class="{
                                        'bg-emerald-500/15': isDrawn({{ $teamId }}),
                                        'opacity-30 grayscale': ! isQualifier({{ $teamId }}),
                                    }"
                                >
                                    <span class="flex size-5 shrink-0 items-center justify-center text-xs font-bold text-white/40">
                                        {{ $index + 1 }}
                                    </span>

                                    <span class="min-w-0 flex-1 truncate font-medium text-white">{{ $row['team']->name }}</span>

                                    <span class="shrink-0 text-xs tabular-nums text-white/40">{{ $row['points'] }} {{ __('pts') }}</span>

                                    <flux:icon.check-circle x-show="isDrawn({{ $teamId }})" x-cloak variant="micro" class="size-4 shrink-0 text-emerald-400" />
                                </div>
                            @empty
                                <div class="px-4 py-3 text-sm text-white/40">{{ __('Sin equipos.') }}</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="grid content-start gap-3">
            @foreach ($matches as $index => $match)
                @php
                    $homePos = $index * 2;
                    $awayPos = $index * 2 + 1;
                @endphp
                <div
                    class="flex items-center justify-between gap-3 rounded-2xl border p-4 transition-colors duration-500"
                    :class="revealed > {{ $awayPos }} ? 'border-white/10 bg-white/5 glass-panel' : 'border-dashed border-white/15 bg-white/[0.03]'"
                >
                    <div class="min-w-0 flex-1 text-right text-sm font-semibold truncate text-white">
                        <span x-show="revealed > {{ $homePos }}" x-cloak class="animate-reveal-pop">{{ $match->homeTeam->name }}</span>
                        <span x-show="revealed <= {{ $homePos }}" class="text-white/30 animate-shimmer-pulse">?</span>
                    </div>

                    <div class="shrink-0 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-accent-content">
                        {{ __('vs') }}
                    </div>

                    <div class="min-w-0 flex-1 text-left text-sm font-semibold truncate text-white">
                        <span x-show="revealed > {{ $awayPos }}" x-cloak class="animate-reveal-pop">{{ $match->awayTeam->name }}</span>
                        <span x-show="revealed <= {{ $awayPos }}" class="text-white/30 animate-shimmer-pulse">?</span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <flux:button variant="primary" x-show="revealed >= total" x-cloak @click="dismissed = true" icon:trailing="arrow-right" class="animate-fade-in-up">
        {{ __('Ver fase') }}
    </flux:button>
</div>
