@props([
    'match',
    'team',
    // Players NOT yet called up for this match, eligible either because
    // they're on $team's own roster or because they're age-eligible to
    // play UP into it from a younger category of the same club -- see
    // Team::clubPlayersEligibleForLineup(). Empty either because every
    // eligible player is already convocado, or because the club has no
    // eligible players at all -- $clubHasEligiblePlayers (computed once in
    // TournamentMatchController::edit(), BEFORE subtracting who's already
    // convocado) is what tells the two apart below.
    'candidates',
    'clubHasEligiblePlayers',
])

@if (! $clubHasEligiblePlayers)
    {{-- Doesn't claim the CLUB has zero players anywhere -- it might have a
         roster in another category that just isn't old/young enough to be
         eligible here (Player::ageEligibleForCategory()). Naming the
         category is what makes that distinction clear instead of implying
         the whole club is empty. --}}
    <x-ui.empty-state icon="user-group" :message="__('El club de :team todavía no tiene jugadores cargados para la categoría :category.', ['team' => $team->name, 'category' => $team->category->name])">
        <x-slot:action>
            <flux:button :href="route('teams.players.create', $team)" variant="primary" size="sm" icon="plus" wire:navigate>
                {{ __('Agregar jugador') }}
            </flux:button>
        </x-slot:action>
    </x-ui.empty-state>
@elseif ($candidates->isNotEmpty())
    {{-- All filtering happens client-side against this already-fetched
         list -- club rosters are small enough that a live search endpoint
         would be overkill, and it keeps this panel consistent with the
         rest of the app's plain-form, no-extra-JS style. Rows stay in the
         DOM and are only hidden (x-show), so a checked box survives typing
         further into the search. --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel" x-data="{ query: '' }">
        <div class="border-b border-zinc-200 px-4 py-2.5 dark:border-white/10">
            <flux:heading size="sm" class="truncate">{{ __('Agregar convocados · :team', ['team' => $team->name]) }}</flux:heading>
        </div>

        <form method="POST" action="{{ route('matches.lineups.store', $match) }}">
            @csrf
            <input type="hidden" name="team_id" value="{{ $team->id }}">

            <div class="px-4 py-2.5">
                <flux:input type="search" x-model="query" icon="magnifying-glass" :placeholder="__('Buscar jugador del club...')" />
            </div>

            <div class="max-h-64 divide-y divide-zinc-100 overflow-y-auto dark:divide-white/5">
                @foreach ($candidates as $player)
                    @php
                        $jsQuery = \Illuminate\Support\Js::from(mb_strtolower($player->full_name));
                        $isOwnRoster = $player->team_id === $team->id || $player->teams->contains('id', $team->id);
                        $originCategory = $player->team?->category?->name;
                    @endphp

                    <label
                        class="flex cursor-pointer items-center justify-between gap-3 px-4 py-2 hover:bg-zinc-50 dark:hover:bg-white/5"
                        x-show="query === '' || {{ $jsQuery }}.includes(query.toLowerCase())"
                        x-cloak
                    >
                        <span class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-1.5">
                            <span class="shrink-0 font-display text-sm font-bold tabular-nums text-zinc-500 dark:text-white/50">#{{ $player->jersey_number ?? '–' }}</span>
                            <span class="truncate text-sm font-medium text-zinc-700 dark:text-white/80">{{ $player->full_name }}</span>

                            @unless ($isOwnRoster)
                                <flux:badge size="sm" color="amber">{{ __('Juega arriba · :category', ['category' => $originCategory ?? '—']) }}</flux:badge>
                            @endunless
                        </span>

                        <input type="checkbox" name="player_ids[]" value="{{ $player->id }}" class="size-4 shrink-0 rounded border-zinc-300 text-accent-content dark:border-white/20">
                    </label>
                @endforeach
            </div>

            <div class="flex justify-end border-t border-zinc-200 px-4 py-3 dark:border-white/10">
                <flux:button type="submit" size="sm" variant="primary" icon="plus">{{ __('Agregar seleccionados') }}</flux:button>
            </div>
        </form>
    </div>
@endif
