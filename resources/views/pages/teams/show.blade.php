@php
    $subtitle = collect([$team->short_name, $team->category->name, $team->group?->name])
        ->filter()
        ->implode(' · ');
@endphp

<x-layouts::app :title="$team->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$team->name" :subtitle="$subtitle">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="$team->tournament_id ? [
                    ['label' => __('Mis torneos'), 'href' => route('dashboard')],
                    ['label' => $team->tournament->name, 'href' => route('tournaments.show', $team->tournament)],
                    ['label' => $team->category->name, 'href' => route('categories.show', $team->category)],
                    ...($team->group ? [['label' => $team->group->name, 'href' => route('groups.show', $team->group)]] : []),
                    ['label' => $team->name],
                ] : [
                    ['label' => __('Clubes'), 'href' => route('clubs.index')],
                    ['label' => $team->club->name, 'href' => route('clubs.show', $team->club)],
                    ['label' => $team->name],
                ]" />
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <flux:button :href="route('teams.edit', $team)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar equipo') }}
                </flux:button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        <flux:separator variant="subtle" />

        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Director técnico') }}</flux:heading>

            <x-ui.coach-card :team="$team" :coach="$team->coach" />
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ __('Jugadores') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">
                        {{ trans_choice(':count jugador activo|:count jugadores activos', $activePlayersCount, ['count' => $activePlayersCount]) }}
                    </flux:text>
                </div>

                <flux:button :href="$team->club_id ? route('clubs.players.create', $team->club) : route('teams.players.create', $team)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Agregar jugador') }}
                </flux:button>
            </div>

            @if ($roster->isEmpty())
                <x-ui.empty-state icon="user-group" :message="__('Todavía no hay jugadores registrados en este equipo.')" />
            @else
                <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                    @foreach ($roster as $player)
                        <x-ui.player-row :player="$player" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
