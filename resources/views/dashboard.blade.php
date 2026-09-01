<x-layouts::app :title="__('Dashboard')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header
            :eyebrow="__('Panel de control')"
            :title="__('Dashboard')"
            :subtitle="__('Bienvenido, :name.', ['name' => auth()->user()->name])"
        >
            <x-slot:actions>
                <flux:button :href="route('tournaments.create')" variant="primary" icon="plus" wire:navigate>
                    {{ __('Nuevo torneo') }}
                </flux:button>
            </x-slot:actions>
        </x-ui.page-header>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card :label="__('Tus torneos')" :value="$tournamentsCount" icon="trophy" color="green" />
            <x-ui.stat-card :label="__('Categorías')" :value="$tournaments->sum('categories_count')" icon="rectangle-group" color="cyan" />
            <x-ui.stat-card :label="__('Equipos')" :value="$tournaments->sum('teams_count')" icon="user-group" color="amber" />
            <x-ui.stat-card :label="__('Partidos')" :value="$tournaments->sum('matches_count')" icon="calendar-days" color="accent" />
        </div>

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Torneos recientes') }}</flux:heading>

                <flux:button :href="route('tournaments.index')" variant="ghost" size="sm" wire:navigate>
                    {{ __('Ver todos') }}
                </flux:button>
            </div>

            @if ($tournaments->isEmpty())
                <x-ui.empty-state icon="trophy" :message="__('Todavía no has creado ningún torneo.')">
                    <x-slot:action>
                        <flux:button :href="route('tournaments.create')" variant="primary" size="sm" icon="plus" wire:navigate>
                            {{ __('Crear torneo') }}
                        </flux:button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($tournaments as $tournament)
                        <x-ui.entity-card
                            :href="route('tournaments.show', $tournament)"
                            :title="$tournament->name"
                            :stats="[
                                trans_choice(':count categoría|:count categorías', $tournament->categories_count, ['count' => $tournament->categories_count]),
                                trans_choice(':count equipo|:count equipos', $tournament->teams_count, ['count' => $tournament->teams_count]),
                                trans_choice(':count partido|:count partidos', $tournament->matches_count, ['count' => $tournament->matches_count]),
                            ]"
                        >
                            <x-slot:badges>
                                <flux:badge size="sm" :color="$tournament->status->color()">{{ $tournament->status->label() }}</flux:badge>
                            </x-slot:badges>
                            <x-slot:description>{{ $tournament->season ?? __('Sin temporada') }}</x-slot:description>
                        </x-ui.entity-card>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
