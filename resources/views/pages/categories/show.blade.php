<x-layouts::app :title="$category->name">
    <div class="w-full space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:button :href="route('tournaments.show', $category->tournament)" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
                    {{ $category->tournament->name }}
                </flux:button>

                <flux:heading size="xl" class="mt-2">{{ $category->name }}</flux:heading>

                <div class="mt-1 flex items-center gap-2">
                    <flux:badge size="sm" :color="$category->status === \App\Enums\CategoryStatus::Active ? 'green' : 'red'">
                        {{ $category->status->label() }}
                    </flux:badge>

                    @if ($category->uses_groups)
                        <flux:badge size="sm" color="zinc">{{ __('Usa grupos') }}</flux:badge>
                    @endif
                </div>

                @if ($category->description)
                    <flux:text class="mt-3 max-w-2xl">{{ $category->description }}</flux:text>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-2">
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
            </div>
        </div>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        <flux:separator />

        @if ($category->uses_groups)
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Grupos') }}</flux:heading>

                    <flux:button :href="route('categories.groups.create', $category)" variant="primary" size="sm" icon="plus" wire:navigate>
                        {{ __('Nuevo grupo') }}
                    </flux:button>
                </div>

                @if ($category->groups->isEmpty())
                    <flux:text class="text-zinc-500">{{ __('Todavía no hay grupos definidos.') }}</flux:text>
                @else
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($category->groups->sortBy('order') as $group)
                            <a href="{{ route('groups.show', $group) }}" wire:navigate class="block">
                                <flux:card class="h-full hover:border-zinc-300 dark:hover:border-white/20">
                                    <flux:heading size="sm">{{ $group->name }}</flux:heading>
                                </flux:card>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            <flux:separator />
        @endif

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Equipos') }}</flux:heading>

                <flux:button :href="route('categories.teams.create', $category)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Nuevo equipo') }}
                </flux:button>
            </div>

            @if ($category->teams->isEmpty())
                <flux:text class="text-zinc-500">{{ __('Todavía no hay equipos registrados.') }}</flux:text>
            @else
                <div class="space-y-2">
                    @foreach ($category->teams->sortBy('name') as $team)
                        <flux:card class="flex items-center justify-between">
                            <div>
                                <flux:heading size="sm">{{ $team->name }}</flux:heading>
                                <flux:text class="text-sm text-zinc-500">
                                    @if ($team->short_name)
                                        {{ $team->short_name }}
                                    @endif
                                    @if ($category->uses_groups)
                                        · {{ $team->group?->name ?? __('Sin grupo') }}
                                    @endif
                                </flux:text>
                            </div>

                            <flux:button :href="route('teams.edit', $team)" variant="ghost" size="sm" icon="pencil" wire:navigate />
                        </flux:card>
                    @endforeach
                </div>
            @endif
        </div>

        <flux:separator />

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Fases') }}</flux:heading>

                <flux:button :href="route('categories.phases.create', $category)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Nueva fase') }}
                </flux:button>
            </div>

            @if ($category->competitionPhases->isEmpty())
                <flux:text class="text-zinc-500">{{ __('Todavía no hay fases definidas.') }}</flux:text>
            @else
                <div class="space-y-2">
                    @foreach ($category->competitionPhases->sortBy('order') as $phase)
                        <a href="{{ route('phases.show', $phase) }}" wire:navigate class="block">
                            <flux:card class="flex items-center justify-between hover:border-zinc-300 dark:hover:border-white/20">
                                <div>
                                    <flux:heading size="sm">{{ $phase->name }}</flux:heading>
                                    <flux:text class="text-sm text-zinc-500">{{ $phase->type->label() }}</flux:text>
                                </div>
                            </flux:card>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <flux:text class="text-sm text-zinc-400">{{ __('Próximamente: calendario y tabla de posiciones.') }}</flux:text>
    </div>
</x-layouts::app>
