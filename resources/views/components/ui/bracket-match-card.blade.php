@props([
    'match',
    'href' => null,
])

@php
    $finished = $match->status === \App\Enums\MatchStatus::Finished;
    $pending = $match->home_team_id === null || $match->away_team_id === null;
    $tag = ($href && ! $pending) ? 'a' : 'div';
    $homeWinner = $finished && $match->home_score > $match->away_score;
    $awayWinner = $finished && $match->away_score > $match->home_score;
    $statusColor = $pending ? 'zinc' : $match->status->color();
    $accentBarClasses = match ($statusColor) {
        'green' => 'bg-green-500/70',
        'cyan' => 'bg-cyan-500/70',
        'amber' => 'bg-amber-500/70',
        'red' => 'bg-red-500/70',
        default => 'bg-zinc-300 dark:bg-white/20',
    };

    $rowClasses = fn (bool $winner): string => $winner
        ? 'font-bold text-zinc-900 dark:text-white'
        : ($pending ? 'italic text-zinc-400 dark:text-white/40' : 'font-medium text-zinc-600 dark:text-white/70');

    $scoreClasses = fn (bool $winner): string => $winner
        ? 'text-zinc-900 dark:text-white'
        : 'text-zinc-400 dark:text-white/40';
@endphp

<{{ $tag }}
    @if ($href && ! $pending) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class('hover-lift group relative block h-16 w-full overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 glass-panel' . ($href && ! $pending ? ' cursor-pointer' : '')) }}
>
    {{-- Status accent: once the match is finished, split it so the top half
         (home) and bottom half (away) each show green for the winner, red
         for the loser, instead of one uniform color for the whole card. --}}
    @if ($finished)
        <div class="absolute inset-y-0 left-0 flex w-1 flex-col">
            <span class="h-1/2 {{ $homeWinner ? 'bg-green-500' : 'bg-red-500' }}"></span>
            <span class="h-1/2 {{ $awayWinner ? 'bg-green-500' : 'bg-red-500' }}"></span>
        </div>
    @else
        <div class="absolute inset-y-0 left-0 w-1 {{ $accentBarClasses }}"></div>
    @endif

    <div class="flex h-8 items-center justify-between gap-2 px-3">
        <span class="truncate text-sm {{ $rowClasses($homeWinner) }}">
            {{ $match->homeTeam?->name ?? __('Por definir') }}
        </span>
        <span class="font-display shrink-0 text-sm font-bold tabular-nums {{ $scoreClasses($homeWinner) }}">
            {{ $match->home_score ?? '–' }}
        </span>
    </div>

    <div class="flex h-8 items-center justify-between gap-2 border-t border-zinc-100 px-3 dark:border-white/5">
        <span class="truncate text-sm {{ $rowClasses($awayWinner) }}">
            {{ $match->awayTeam?->name ?? __('Por definir') }}
        </span>
        <span class="font-display shrink-0 text-sm font-bold tabular-nums {{ $scoreClasses($awayWinner) }}">
            {{ $match->away_score ?? '–' }}
        </span>
    </div>
</{{ $tag }}>
