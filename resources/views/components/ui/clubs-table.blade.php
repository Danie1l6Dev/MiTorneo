@props([
    'label' => null,
    'teams' => [],
    'amber' => false,
    'incompleteTeamIds' => [],
])

{{--
    Deliberately one row layout for every screen size (no separate <table>
    for desktop) -- a semantic <table> measures its own height unreliably
    while it's the last thing inside an element Alpine's x-collapse is
    animating (a well-known browser quirk), which showed up here as the
    accordion opening to a sliver with the real rows clipped out even
    though they were present in the DOM. Plain rows don't have that
    problem and match the row style already used elsewhere (see
    x-ui.team-row).
--}}
<div {{ $attributes->class('overflow-hidden rounded-2xl border ' . ($amber ? 'border-amber-500/30' : 'border-zinc-200 dark:border-white/10') . ' glass-panel') }}>
    @if ($label)
        <div class="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-white/10">
            @if ($amber)
                <flux:icon.exclamation-triangle variant="micro" class="size-3.5 text-amber-500" />
            @else
                <flux:icon.squares-2x2 variant="micro" class="size-3.5 text-zinc-400" />
            @endif
            <flux:heading size="sm">{{ $label }}</flux:heading>
        </div>
    @endif

    @if (count($teams) === 0)
        <div class="p-6">
            <x-ui.empty-state icon="shield-check" :message="__('Ningún club tiene plantel acá todavía.')" />
        </div>
    @else
        <div class="divide-y divide-zinc-100 dark:divide-white/5">
            @foreach ($teams as $team)
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    @php
                        $hasIncomplete = in_array($team->id, $incompleteTeamIds);
                        $squadNameDiffers = $team->club->name !== $team->name;
                    @endphp

                    <a href="{{ route('clubs.show', $team->club) }}" wire:navigate class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $team->club->name }}</div>

                        @if ($squadNameDiffers || $hasIncomplete)
                            <div class="flex items-center gap-1.5 truncate text-xs text-zinc-500 dark:text-white/50">
                                @if ($squadNameDiffers)
                                    {{ $team->name }}
                                @endif

                                @if ($hasIncomplete)
                                    <flux:tooltip :content="__('Tiene jugadores sin fecha de nacimiento -- no se puede validar del todo en qué categorías pueden jugar')">
                                        <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0 text-amber-500" />
                                    </flux:tooltip>
                                @endif
                            </div>
                        @endif
                    </a>

                    <div class="flex shrink-0 items-center gap-2">
                        <flux:badge size="sm" color="zinc">{{ trans_choice(':count jugador|:count jugadores', $team->rosterPlayersCount(), ['count' => $team->rosterPlayersCount()]) }}</flux:badge>
                        <flux:button :href="route('teams.show', $team)" variant="ghost" size="sm" wire:navigate>{{ __('Ver plantel') }}</flux:button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
