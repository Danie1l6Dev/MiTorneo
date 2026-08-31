<x-layouts::app :title="$category->name">
    <div class="w-full space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:button :href="route('tournaments.show', $category->tournament)" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
                    {{ $category->tournament->name }}
                </flux:button>

                <flux:heading size="xl" class="mt-2">{{ $category->name }}</flux:heading>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <flux:button :href="route('categories.edit', $category)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <form method="POST" action="{{ route('categories.destroy', $category) }}" onsubmit="return confirm('{{ __('¿Eliminar esta categoría? Se eliminarán también sus fases y equipos.') }}')">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </form>
            </div>
        </div>

        <flux:separator />

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Fases de competición') }}</flux:heading>

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
                                    @if ($team->short_name)
                                        <flux:text class="text-sm text-zinc-500">{{ $team->short_name }}</flux:text>
                                    @endif
                                </div>

                                <flux:button :href="route('teams.edit', $team)" variant="ghost" size="sm" icon="pencil" wire:navigate />
                            </flux:card>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layouts::app>
