@props([
    'match',
    'href' => null,
    'cardClass' => 'h-16',
    'rowClass' => 'h-8',
    'textClass' => 'text-sm',
    // The "¿Ida o vuelta?" picker links straight to matches.edit (an admin,
    // auth-only route) regardless of $href -- so the public portal's
    // read-only bracket view (see PublicPhaseController) passes false here
    // to fall back to a plain, unclickable card instead of ever rendering
    // that admin link.
    'allowPicker' => true,
])

@php
    $isSecondLeg = $match->first_leg_match_id !== null;
    $finished = $isSecondLeg
        ? $match->status === \App\Enums\MatchStatus::Finished && $match->firstLeg->status === \App\Enums\MatchStatus::Finished
        : $match->status === \App\Enums\MatchStatus::Finished;
    $pending = $match->home_team_id === null || $match->away_team_id === null;
    $tag = ($href && ! $pending) ? 'a' : 'div';
    $winnerTeamId = $match->tieWinnerTeamId();
    $homeWinner = $winnerTeamId !== null && $winnerTeamId === $match->home_team_id;
    $awayWinner = $winnerTeamId !== null && $winnerTeamId === $match->away_team_id;
    $statusColor = $pending ? 'zinc' : $match->status->color();

    // The main score shown is the aggregate (regular + extra time, plus --
    // for the decisive leg of a two-legged cross -- the first leg's score
    // too), since that's what actually decides the cross. When it's still
    // level, a penalty shoot-out is what actually separates the teams, so
    // each side's own penalty tally is appended next to its score (e.g.
    // "1 (5) - 1 (4)").
    $wentToExtraTime = $match->home_extra_time_score !== null && $match->away_extra_time_score !== null;
    $wentToPenalties = $match->home_penalty_score !== null && $match->away_penalty_score !== null;
    $aggregate = $match->regularTimeAggregate();
    $homeDisplayScore = $aggregate !== null ? $aggregate['home'] + ($match->home_extra_time_score ?? 0) : null;
    $awayDisplayScore = $aggregate !== null ? $aggregate['away'] + ($match->away_extra_time_score ?? 0) : null;
    $firstLegLine = $isSecondLeg && $match->firstLeg->home_score !== null
        ? __('Ida :home-:away', ['home' => $match->firstLeg->home_score, 'away' => $match->firstLeg->away_score])
        : null;
    $tiebreakTitle = collect([
        $firstLegLine,
        match (true) {
            $wentToPenalties => __('Definido por penales (:home-:away)', ['home' => $match->home_penalty_score, 'away' => $match->away_penalty_score]),
            $wentToExtraTime => __('Resultado incluye la prórroga'),
            default => null,
        },
    ])->filter()->implode(' · ') ?: null;
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

    // A two-legged cross's card only ever shows the decisive (second) leg --
    // clicking it can't go straight to "the" match, since there are two. It
    // opens a small picker modal instead (skipped entirely for a
    // single-match cross or a league match, which still just link straight
    // through like before).
    $opensPicker = $allowPicker && $isSecondLeg && ! $pending;
    $cardInnerClasses = 'hover-lift group relative block h-full w-full overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 glass-panel';

    // One small red-card icon per expulsion, next to the team's own name --
    // rows here are too compact (h-6 to h-8) to put it underneath the way
    // the calendar's x-ui.match-card does.
    $homeRedCards = $match->home_team_id ? $match->redCardCountForTeam($match->home_team_id) : 0;
    $awayRedCards = $match->away_team_id ? $match->redCardCountForTeam($match->away_team_id) : 0;
@endphp

@php
    // Captured once so the exact same markup renders whether it ends up
    // wrapped in a plain link/div or inside the picker modal's trigger --
    // only the wrapper differs, never the card's own content.
    ob_start();
@endphp
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
    <span class="flex min-w-0 items-center gap-1">
        <span class="truncate {{ $textClass }} {{ $rowClasses($homeWinner) }}">
            {{ $match->homeTeam?->name ?? __('Por definir') }}
        </span>
        @if ($homeRedCards > 0)
            <span class="flex shrink-0 items-center gap-0.5" title="{{ trans_choice(':count expulsado|:count expulsados', $homeRedCards, ['count' => $homeRedCards]) }}">
                @for ($i = 0; $i < $homeRedCards; $i++)
                    <x-dynamic-component :component="\App\Enums\MatchEventType::RedCard->icon()" class="size-2.5 shrink-0 text-red-500" />
                @endfor
            </span>
        @endif
    </span>
    <span class="font-display shrink-0 {{ $textClass }} font-bold tabular-nums {{ $scoreClasses($homeWinner) }}">
        {{ $homeDisplayScore ?? '–' }}
        @if ($wentToPenalties)
            <span class="text-[0.65em] font-normal opacity-70">({{ $match->home_penalty_score }})</span>
        @endif
    </span>
</div>

<div class="{{ $rowClass }} flex items-center justify-between gap-2 border-t border-zinc-100 px-3 dark:border-white/5">
    <span class="flex min-w-0 items-center gap-1">
        <span class="truncate {{ $textClass }} {{ $rowClasses($awayWinner) }}">
            {{ $match->awayTeam?->name ?? __('Por definir') }}
        </span>
        @if ($awayRedCards > 0)
            <span class="flex shrink-0 items-center gap-0.5" title="{{ trans_choice(':count expulsado|:count expulsados', $awayRedCards, ['count' => $awayRedCards]) }}">
                @for ($i = 0; $i < $awayRedCards; $i++)
                    <x-dynamic-component :component="\App\Enums\MatchEventType::RedCard->icon()" class="size-2.5 shrink-0 text-red-500" />
                @endfor
            </span>
        @endif
    </span>
    <span class="font-display shrink-0 {{ $textClass }} font-bold tabular-nums {{ $scoreClasses($awayWinner) }}">
        {{ $awayDisplayScore ?? '–' }}
        @if ($wentToPenalties)
            <span class="text-[0.65em] font-normal opacity-70">({{ $match->away_penalty_score }})</span>
        @endif
    </span>
</div>
@php
    $cardBody = ob_get_clean();
@endphp

<div {{ $attributes->class('relative w-full ' . $cardClass) }}>
    @if ($opensPicker)
        <flux:modal.trigger name="cross-{{ $match->first_leg_match_id }}">
            <div
                @if ($tiebreakTitle) title="{{ $tiebreakTitle }}" @endif
                class="{{ $cardInnerClasses }} cursor-pointer"
            >
                {!! $cardBody !!}
            </div>
        </flux:modal.trigger>

        <flux:modal name="cross-{{ $match->first_leg_match_id }}" class="max-w-sm">
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('¿Ida o vuelta?') }}</flux:heading>
                    <flux:subheading>
                        {{ $match->firstLeg->homeTeam?->name ?? __('Por definir') }}
                        {{ __('vs') }}
                        {{ $match->firstLeg->awayTeam?->name ?? __('Por definir') }}
                    </flux:subheading>
                </div>

                <div class="grid gap-2">
                    <flux:button :href="route('matches.edit', $match->firstLeg)" wire:navigate class="justify-center">
                        {{ __('Partido de ida') }}
                        @if ($match->firstLeg->home_score !== null)
                            <span class="ml-1 text-zinc-400">({{ $match->firstLeg->home_score }}-{{ $match->firstLeg->away_score }})</span>
                        @endif
                    </flux:button>

                    <flux:button :href="route('matches.edit', $match)" wire:navigate variant="primary" class="justify-center">
                        {{ __('Partido de vuelta') }}
                        @if ($match->home_score !== null)
                            <span class="ml-1 opacity-70">({{ $match->home_score }}-{{ $match->away_score }})</span>
                        @endif
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @else
        <{{ $tag }}
            @if ($href && ! $pending) href="{{ $href }}" wire:navigate @endif
            @if ($tiebreakTitle) title="{{ $tiebreakTitle }}" @endif
            class="{{ $cardInnerClasses }} {{ $href && ! $pending ? 'cursor-pointer' : '' }}"
        >
            {!! $cardBody !!}
        </{{ $tag }}>
    @endif

    {{-- Sits outside the card's own wrapper on purpose -- nested inside a
         link/modal-trigger, hovering the icon would just preview that
         "click to open" affordance instead of a tooltip, since the whole
         card is one big clickable area. Centered above the top edge (not
         right-1/top-1, unlike the calendar's plain x-ui.match-card) since
         this card's rows put each team's score flush against the right
         edge -- a corner badge there would sit right on top of the home
         team's goal count instead of beside it. --}}
    @if ($finished && $match->hasGoalMismatch())
        <flux:tooltip :content="__('Los goles registrados como eventos no coinciden con el marcador.')">
            <div class="absolute -top-1.5 left-1/2 flex size-4 -translate-x-1/2 animate-pulse items-center justify-center rounded-full bg-white shadow ring-1 ring-amber-500/50 dark:bg-zinc-900">
                <flux:icon.exclamation-triangle variant="mini" class="size-2.5 text-amber-500 dark:text-amber-400" />
            </div>
        </flux:tooltip>
    @endif
</div>
