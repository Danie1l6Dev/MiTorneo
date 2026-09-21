@props([
    // Collection<Player> -- this team's own roster players who no longer
    // fit its category's age rule (Player::ageEligibleForCategory()),
    // typically because the category's allowed years were edited after
    // they were already rostered. See Team::ineligibleRosterPlayers() --
    // clubPlayersEligibleForLineup() silently excludes them from the
    // quick-add roster panel above instead of offering them event buttons,
    // so this card is what actually explains why they're missing from it.
    'players',
    'team',
])

@if ($players->isNotEmpty())
    <div class="overflow-hidden rounded-2xl border border-red-200 bg-red-50/60 dark:border-red-500/20 dark:bg-red-500/[0.04]">
        <div class="flex items-center gap-2 border-b border-red-200 px-4 py-2.5 dark:border-red-500/20">
            <flux:icon.exclamation-triangle variant="micro" class="size-4 text-red-500" />
            <flux:heading size="sm" class="text-red-700 dark:text-red-300">{{ __('Ya no pueden jugar en esta categoría') }}</flux:heading>
        </div>

        <div class="divide-y divide-red-100 dark:divide-red-500/10">
            @foreach ($players as $player)
                <a
                    href="{{ route('teams.players.promote.create', [$team, $player]) }}"
                    wire:navigate
                    class="flex items-center justify-between gap-3 px-4 py-2.5 transition-colors hover:bg-red-100/60 dark:hover:bg-red-500/10"
                >
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $player->full_name }}</div>
                        <div class="mt-0.5 text-xs text-zinc-500 dark:text-white/50">
                            {{ __('Nació en :year -- :category es solo para jugadores de :from-:to.', [
                                'year' => $player->birth_date->format('Y'),
                                'category' => $team->category->name,
                                'from' => $team->category->birth_year_from ?? '—',
                                'to' => $team->category->birth_year_to ?? '—',
                            ]) }}
                        </div>
                    </div>

                    <flux:badge size="sm" color="red">{{ __('Promover') }}</flux:badge>
                </a>
            @endforeach
        </div>
    </div>
@endif
