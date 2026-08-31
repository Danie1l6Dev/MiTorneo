<x-layouts::app :title="$phase->name">
    <div class="w-full space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:button :href="route('categories.show', $phase->category)" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
                    {{ $phase->category->name }}
                </flux:button>

                <flux:heading size="xl" class="mt-2">{{ $phase->name }}</flux:heading>
                <flux:badge size="sm" class="mt-1">{{ $phase->type->label() }}</flux:badge>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <flux:button :href="route('phases.edit', $phase)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <form method="POST" action="{{ route('phases.destroy', $phase) }}" onsubmit="return confirm('{{ __('¿Eliminar esta fase? Se eliminarán también sus grupos y partidos.') }}')">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </form>
            </div>
        </div>

        <flux:separator />

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Grupos') }}</flux:heading>

                <flux:button :href="route('phases.groups.create', $phase)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Nuevo grupo') }}
                </flux:button>
            </div>

            @if ($phase->groups->isEmpty())
                <flux:text class="text-zinc-500">
                    {{ __('Esta fase no usa grupos. Los partidos se cargan directamente sobre la fase.') }}
                </flux:text>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($phase->groups->sortBy('order') as $group)
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

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Partidos') }}</flux:heading>

                <flux:button :href="route('phases.matches.create', $phase)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Nuevo partido') }}
                </flux:button>
            </div>

            @if ($phase->matches->isEmpty())
                <flux:text class="text-zinc-500">{{ __('Todavía no hay partidos cargados en esta fase.') }}</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Local') }}</flux:table.column>
                        <flux:table.column>{{ __('Visitante') }}</flux:table.column>
                        <flux:table.column>{{ __('Resultado') }}</flux:table.column>
                        <flux:table.column>{{ __('Estado') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($phase->matches as $match)
                            <flux:table.row>
                                <flux:table.cell>{{ $match->homeTeam->name }}</flux:table.cell>
                                <flux:table.cell>{{ $match->awayTeam->name }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $match->home_score ?? '-' }} : {{ $match->away_score ?? '-' }}
                                </flux:table.cell>
                                <flux:table.cell>{{ $match->status->label() }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:button :href="route('matches.edit', $match)" variant="ghost" size="sm" icon="pencil" wire:navigate />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    </div>
</x-layouts::app>
