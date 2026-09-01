<x-layouts::app :title="$category->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$category->name" :subtitle="$category->description">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Mis torneos'), 'href' => route('tournaments.index')],
                    ['label' => $category->tournament->name, 'href' => route('tournaments.show', $category->tournament)],
                    ['label' => $category->name],
                ]" />
            </x-slot:breadcrumbs>

            <div class="mt-1 flex items-center gap-2">
                <flux:badge size="sm" :color="$category->status->color()">{{ $category->status->label() }}</flux:badge>

                @if ($category->uses_groups)
                    <flux:badge size="sm" color="zinc">{{ __('Usa grupos') }}</flux:badge>
                @endif
            </div>

            <x-slot:actions>
                <form method="POST" action="{{ route('categories.toggle-status', $category) }}">
                    @csrf
                    @method('PATCH')
                    <flux:button type="submit" variant="ghost">
                        {{ $category->status === \App\Enums\CategoryStatus::Active ? __('Desactivar') : __('Activar') }}
                    </flux:button>
                </form>

                <flux:button :href="route('categories.edit', $category)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                @php
                    $hasDependencies = $category->groups->isNotEmpty()
                        || $category->teams->isNotEmpty()
                        || $category->competitionPhases->isNotEmpty();
                @endphp

                @if ($hasDependencies)
                    <form method="POST" action="{{ route('categories.destroy', $category) }}" onsubmit="return confirm('{{ __('Esta categoría tiene contenido asociado. ¿Eliminarla junto con todos sus grupos, equipos y fases?') }}')">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="force" value="1">
                        <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar de todas formas') }}</flux:button>
                    </form>
                @else
                    <form method="POST" action="{{ route('categories.destroy', $category) }}" onsubmit="return confirm('{{ __('¿Eliminar esta categoría?') }}')">
                        @csrf
                        @method('DELETE')
                        <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                    </form>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        <flux:separator variant="subtle" />

        @if ($category->uses_groups)
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Grupos') }}</flux:heading>

                    <flux:button :href="route('categories.groups.create', $category)" variant="primary" size="sm" icon="plus" wire:navigate>
                        {{ __('Nuevo grupo') }}
                    </flux:button>
                </div>

                @if ($category->groups->isEmpty())
                    <x-ui.empty-state icon="squares-2x2" :message="__('Todavía no hay grupos definidos.')" />
                @else
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($category->groups->sortBy('order') as $group)
                            <x-ui.entity-card
                                :href="route('groups.show', $group)"
                                :title="$group->name"
                                :stats="[trans_choice(':count equipo|:count equipos', $group->teams_count, ['count' => $group->teams_count])]"
                                :cta="__('Ver grupo')"
                            />
                        @endforeach
                    </div>
                @endif
            </div>

            <flux:separator variant="subtle" />
        @endif

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Equipos') }}</flux:heading>

                <flux:button :href="route('categories.teams.create', $category)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Nuevo equipo') }}
                </flux:button>
            </div>

            @if ($category->teams->isEmpty())
                <x-ui.empty-state icon="user-group" :message="__('Todavía no hay equipos registrados.')" />
            @else
                <div class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                    @foreach ($category->teams->sortBy('name') as $team)
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-content/15 text-xs font-bold uppercase text-accent-content">
                                    {{ \Illuminate\Support\Str::substr($team->short_name ?: $team->name, 0, 2) }}
                                </div>

                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $team->name }}</div>
                                    <div class="truncate text-xs text-zinc-500 dark:text-white/50">
                                        @if ($team->short_name)
                                            {{ $team->short_name }}
                                        @endif
                                        @if ($category->uses_groups)
                                            · {{ $team->group?->name ?? __('Sin grupo') }}
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <flux:button :href="route('teams.edit', $team)" variant="ghost" size="sm" icon="pencil" wire:navigate />
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Fases') }}</flux:heading>

                <flux:button :href="route('categories.phases.create', $category)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Nueva fase') }}
                </flux:button>
            </div>

            @if ($category->competitionPhases->isEmpty())
                <x-ui.empty-state icon="calendar-days" :message="__('Todavía no hay fases definidas.')" />
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($category->competitionPhases->sortBy('order') as $phase)
                        <x-ui.entity-card
                            :href="route('phases.show', $phase)"
                            :title="$phase->name"
                            :stats="[trans_choice(':count partido|:count partidos', $phase->matches_count, ['count' => $phase->matches_count])]"
                            :cta="__('Ver fase')"
                        >
                            <x-slot:badges>
                                <flux:badge size="sm" :color="$phase->type->color()">{{ $phase->type->label() }}</flux:badge>
                            </x-slot:badges>
                        </x-ui.entity-card>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
