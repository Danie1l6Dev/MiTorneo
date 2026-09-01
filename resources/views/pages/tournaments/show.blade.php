<x-layouts::app :title="$tournament->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$tournament->name" :subtitle="$tournament->description">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Mis torneos'), 'href' => route('tournaments.index')],
                    ['label' => $tournament->name],
                ]" />
            </x-slot:breadcrumbs>

            <div class="mt-1 flex items-center gap-2">
                <flux:badge size="sm" :color="$tournament->status->color()">{{ $tournament->status->label() }}</flux:badge>

                @if ($tournament->season)
                    <flux:text class="text-sm text-zinc-500">{{ $tournament->season }}</flux:text>
                @endif
            </div>

            <x-slot:actions>
                <flux:button :href="route('tournaments.edit', $tournament)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <form method="POST" action="{{ route('tournaments.destroy', $tournament) }}" onsubmit="return confirm('{{ __('¿Eliminar este torneo? Se eliminarán también sus categorías, equipos y partidos.') }}')">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.stat-card :label="__('Categorías')" :value="$tournament->categories_count" icon="rectangle-group" color="cyan" />
            <x-ui.stat-card :label="__('Equipos')" :value="$tournament->teams_count" icon="user-group" color="amber" />
            <x-ui.stat-card :label="__('Partidos')" :value="$tournament->matches_count" icon="calendar-days" color="accent" />
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Categorías') }}</flux:heading>

                <flux:button :href="route('tournaments.categories.create', $tournament)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Nueva categoría') }}
                </flux:button>
            </div>

            @if ($tournament->categories->isEmpty())
                <x-ui.empty-state icon="rectangle-group" :message="__('Este torneo todavía no tiene categorías.')">
                    <x-slot:action>
                        <flux:button :href="route('tournaments.categories.create', $tournament)" variant="primary" size="sm" icon="plus" wire:navigate>
                            {{ __('Nueva categoría') }}
                        </flux:button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($tournament->categories->sortBy('order') as $category)
                        <x-ui.entity-card
                            :href="route('categories.show', $category)"
                            :title="$category->name"
                            :stats="[
                                trans_choice(':count equipo|:count equipos', $category->teams_count, ['count' => $category->teams_count]),
                                $category->uses_groups ? trans_choice(':count grupo|:count grupos', $category->groups_count, ['count' => $category->groups_count]) : __('Sin grupos'),
                            ]"
                        >
                            <x-slot:badges>
                                <flux:badge size="sm" :color="$category->status->color()">{{ $category->status->label() }}</flux:badge>
                            </x-slot:badges>
                        </x-ui.entity-card>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
