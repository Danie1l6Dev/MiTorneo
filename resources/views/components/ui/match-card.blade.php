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

    <{{ $tag }}
        @if ($href && ! $pending) href="{{ $href }}" wire:navigate @endif
        {{ $attributes->class('hover-lift group block rounded-3xl border border-t-2 border-zinc-200 bg-white p-6 dark:border-white/10 glass-panel ' . $accentClasses . ' ' . $widthClasses . ($href && ! $pending ? ' cursor-pointer' : '')) }}
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
@endif
