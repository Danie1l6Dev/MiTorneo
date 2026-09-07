@props([
    'match' => null,
    'resting' => null,
    'href' => null,
    'fullWidth' => false,
])

@php
    $widthClasses = $fullWidth ? 'w-full' : 'w-full sm:w-[calc(50%-0.5rem)] lg:w-[calc(33.333%-0.667rem)] lg:max-w-sm';
@endphp

@if ($resting)
    <div {{ $attributes->class('flex flex-col items-center justify-center gap-2 rounded-3xl border border-dashed border-zinc-300 bg-zinc-50/60 px-4 py-7 text-center dark:border-white/15 dark:bg-white/[0.03] ' . $widthClasses) }}>
        <flux:icon.moon variant="outline" class="size-5 text-zinc-400 dark:text-white/40" />
        <div class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-white/40">{{ __('DESCANSA') }}</div>
        <div class="text-base font-medium text-zinc-600 dark:text-white/70">{{ $resting }}</div>
    </div>
@else
    @php
        $finished = $match->status === \App\Enums\MatchStatus::Finished;
        $pending = $match->home_team_id === null || $match->away_team_id === null;
        $tag = ($href && ! $pending) ? 'a' : 'div';
        $scoreClasses = $finished ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 dark:text-white/40';
        $accentClasses = match ($match->status->color()) {
            'green' => 'border-t-green-500/70',
            'cyan' => 'border-t-cyan-500/70',
            'amber' => 'border-t-amber-500/70',
            'red' => 'border-t-red-500/70',
            default => 'border-t-zinc-300 dark:border-t-white/20',
        };
    @endphp

    <div {{ $attributes->class('relative ' . $widthClasses) }}>
        <{{ $tag }}
            @if ($href && ! $pending) href="{{ $href }}" wire:navigate @endif
            class="hover-lift group block h-full w-full rounded-3xl border border-t-2 border-zinc-200 bg-white p-6 dark:border-white/10 glass-panel {{ $accentClasses }} {{ $href && ! $pending ? 'cursor-pointer' : '' }}"
        >
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0 flex-1 text-right text-base font-semibold truncate text-zinc-800 dark:text-white">
                    {{ $match->homeTeam?->name ?? __('Por definir') }}
                </div>

                <div class="flex shrink-0 items-center gap-2 rounded-xl bg-zinc-100 px-3.5 py-2 dark:bg-white/10">
                    <span class="font-display text-xl font-bold tabular-nums {{ $scoreClasses }}">{{ $match->home_score ?? '–' }}</span>
                    <span class="text-zinc-400 dark:text-white/30">-</span>
                    <span class="font-display text-xl font-bold tabular-nums {{ $scoreClasses }}">{{ $match->away_score ?? '–' }}</span>
                </div>

                <div class="min-w-0 flex-1 text-left text-base font-semibold truncate text-zinc-800 dark:text-white">
                    {{ $match->awayTeam?->name ?? __('Por definir') }}
                </div>
            </div>

            <div class="mt-4 flex items-center justify-center">
                @if ($pending)
                    <flux:badge size="sm" color="zinc">{{ mb_strtoupper(__('Por definir')) }}</flux:badge>
                @else
                    <flux:badge size="sm" :color="$match->status->color()">{{ mb_strtoupper($match->status->label()) }}</flux:badge>
                @endif
            </div>
        </{{ $tag }}>

        {{-- Sits outside the <a> on purpose -- nested inside it, hovering
             the icon just previews the "click to open this match" affordance
             (cursor + hover-lift) instead of a tooltip, since the whole card
             is one big link. Inset (not overhanging the corner) so it never
             pokes into the round label above or the next card in the row. --}}
        @if ($finished && $match->hasGoalMismatch())
            <flux:tooltip :content="__('Los goles registrados como eventos no coinciden con el marcador.')">
                <div class="absolute right-2 top-2 flex size-6 animate-pulse items-center justify-center rounded-full bg-white shadow ring-1 ring-amber-500/50 dark:bg-zinc-900">
                    <flux:icon.exclamation-triangle variant="mini" class="size-3.5 text-amber-500 dark:text-amber-400" />
                </div>
            </flux:tooltip>
        @endif
    </div>
@endif
