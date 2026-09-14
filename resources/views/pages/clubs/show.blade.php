@php
    // Nested: category name => group name (or "Sin grupo") => teams --
    // same organizing principle as the Clubes index and Categoría show
    // pages, applied here per-club.
    $teamsByCategory = $club->teams
        ->groupBy('category.name')
        ->map(fn ($teams) => $teams->groupBy(fn ($team) => $team->group->name ?? __('Sin grupo')));
@endphp

<x-layouts::app :title="$club->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$club->name" :subtitle="trans_choice(':count plantel|:count planteles', $club->teams->count(), ['count' => $club->teams->count()])">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Clubes'), 'href' => route('clubs.index')],
                    ['label' => $club->name],
                ]" />
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <flux:button :href="route('clubs.edit', $club)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <x-ui.confirm-delete-form
                    :action="route('clubs.destroy', $club)"
                    :heading="__('¿Eliminar este club?')"
                    :description="__('Esta acción no se puede deshacer.')"
                >
                    <flux:button variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </x-ui.confirm-delete-form>
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
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Planteles') }}</flux:heading>

                @if ($availableCategories->isEmpty())
                    <flux:tooltip :content="__('Primero crea una categoría en tu catálogo.')">
                        <flux:button variant="primary" size="sm" icon="plus" disabled>
                            {{ __('Nuevo plantel') }}
                        </flux:button>
                    </flux:tooltip>
                @else
                    <flux:button :href="route('clubs.teams.create', $club)" variant="primary" size="sm" icon="plus" wire:navigate>
                        {{ __('Nuevo plantel') }}
                    </flux:button>
                @endif
            </div>

            @if ($club->teams->isEmpty())
                <x-ui.empty-state icon="user-group" :message="__('Todavía no hay planteles registrados para este club.')" />
            @else
                <div class="space-y-6">
                    @foreach ($teamsByCategory as $categoryName => $teamsByGroup)
                        <div class="space-y-3">
                            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                <flux:icon.rectangle-stack variant="micro" class="size-3.5" />
                                {{ $categoryName }}
                            </div>

                            @foreach ($teamsByGroup as $groupName => $teams)
                                <div>
                                    @if ($teamsByGroup->count() > 1)
                                        <div class="mb-1.5 flex items-center gap-2 pl-1 text-xs text-zinc-400 dark:text-white/40">
                                            <flux:icon.squares-2x2 variant="micro" class="size-3" />
                                            {{ $groupName }}
                                        </div>
                                    @endif

                                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                                        @foreach ($teams->sortBy('name') as $team)
                                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                                                <a href="{{ route('teams.show', $team) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-1.5 text-sm font-medium text-zinc-800 dark:text-white">
                                                    {{ $team->name }}
                                                    @if (in_array($team->id, $incompleteTeamIds))
                                                        <flux:tooltip :content="__('Tiene jugadores sin fecha de nacimiento -- no se puede validar del todo en qué categorías pueden jugar')">
                                                            <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0 text-amber-500" />
                                                        </flux:tooltip>
                                                    @endif
                                                </a>

                                                <div class="flex shrink-0 items-center gap-2">
                                                    <flux:badge size="sm" color="zinc">
                                                        {{ trans_choice(':count jugador|:count jugadores', $team->globalPlayers()->count(), ['count' => $team->globalPlayers()->count()]) }}
                                                    </flux:badge>

                                                    <x-ui.confirm-delete-form
                                                        :action="route('teams.destroy', $team)"
                                                        :heading="__('¿Eliminar :team?', ['team' => $team->name])"
                                                        :description="__('Se eliminarán también sus jugadores y su historial de partidos. Esta acción no se puede deshacer.')"
                                                        :confirm-label="__('Eliminar plantel')"
                                                    >
                                                        <flux:button variant="ghost" size="sm" icon="trash" />
                                                    </x-ui.confirm-delete-form>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
