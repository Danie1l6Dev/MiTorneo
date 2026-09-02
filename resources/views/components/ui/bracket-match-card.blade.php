@props([
    'match',
    'href' => null,
    'cardClass' => 'h-16',
    'rowClass' => 'h-8',
    'textClass' => 'text-sm',
])

@php
    $finished = $match->status === \App\Enums\MatchStatus::Finished;
    $pending = $match->home_team_id === null || $match->away_team_id === null;
    $tag = ($href && ! $pending) ? 'a' : 'div';
    $winnerTeamId = $match->winnerTeamId();
    $homeWinner = $winnerTeamId !== null && $winnerTeamId === $match->home_team_id;
    $awayWinner = $winnerTeamId !== null && $winnerTeamId === $match->away_team_id;
    $statusColor = $pending ? 'zinc' : $match->status->color();

    // The main score shown is the aggregate (regular + extra time), since
    // that's the actual final score -- it alone already reflects a match
    // decided in extra time. When the aggregate is still level, a penalty
    // shoot-out is what actually separates the teams, so each side's own
    // penalty tally is appended next to its score (e.g. "1 (5) - 1 (4)").
    $wentToExtraTime = $match->home_extra_time_score !== null && $match->away_extra_time_score !== null;
    $wentToPenalties = $match->home_penalty_score !== null && $match->away_penalty_score !== null;
    $homeDisplayScore = $match->home_score !== null ? $match->home_score + ($match->home_extra_time_score ?? 0) : null;
    $awayDisplayScore = $match->away_score !== null ? $match->away_score + ($match->away_extra_time_score ?? 0) : null;
    $tiebreakTitle = match (true) {
        $wentToPenalties => __('Definido por penales (:home-:away)', ['home' => $match->home_penalty_score, 'away' => $match->away_penalty_score]),
        $wentToExtraTime => __('Resultado incluye la prórroga'),
        default => null,
    };
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
    @if ($tiebreakTitle) title="{{ $tiebreakTitle }}" @endif
    {{ $attributes->class('hover-lift group relative block w-full overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 glass-panel ' . $cardClass . ($href && ! $pending ? ' cursor-pointer' : '')) }}
>
    {{-- Status accent: once the match is finished, split it so the top half
         (home) and bottom half (away) each show green for the winner, red
         for the loser, instead of one uniform color for the whole card. --}}
    @if ($finished && $winnerTeamId !== null)
        <div class="absolute inset-y-0 left-0 flex w-1 flex-col">
            <span class="h-1/2 {{ $homeWinner ? 'bg-green-500' : 'bg-red-500' }}"></span>
            <span class="h-1/2 {{ $awayWinner ? 'bg-green-500' : 'bg-red-500' }}"></span>
        </div>
    @else
        <div class="absolute inset-y-0 left-0 w-1 {{ $accentBarClasses }}"></div>
    @endif

    <div class="{{ $rowClass }} flex items-center justify-between gap-2 px-3">
        <span class="truncate {{ $textClass }} {{ $rowClasses($homeWinner) }}">
            {{ $match->homeTeam?->name ?? __('Por definir') }}
        </span>
        <span class="font-display shrink-0 {{ $textClass }} font-bold tabular-nums {{ $scoreClasses($homeWinner) }}">
            {{ $homeDisplayScore ?? '–' }}
            @if ($wentToPenalties)
                <span class="text-[0.65em] font-normal opacity-70">({{ $match->home_penalty_score }})</span>
            @endif
        </span>
    </div>

    <div class="{{ $rowClass }} flex items-center justify-between gap-2 border-t border-zinc-100 px-3 dark:border-white/5">
        <span class="truncate {{ $textClass }} {{ $rowClasses($awayWinner) }}">
            {{ $match->awayTeam?->name ?? __('Por definir') }}
        </span>
        <span class="font-display shrink-0 {{ $textClass }} font-bold tabular-nums {{ $scoreClasses($awayWinner) }}">
            {{ $awayDisplayScore ?? '–' }}
            @if ($wentToPenalties)
                <span class="text-[0.65em] font-normal opacity-70">({{ $match->away_penalty_score }})</span>
            @endif
        </span>
    </div>
</{{ $tag }}>
