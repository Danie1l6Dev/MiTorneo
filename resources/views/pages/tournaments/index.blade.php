<x-layouts::app :title="__('Torneos')">
    <div class="w-full space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Mis torneos')" :subtitle="__('Administra los torneos que has creado.')">
            <x-slot:actions>
                <flux:button :href="route('tournaments.create')" variant="primary" icon="plus" wire:navigate>
                    {{ __('Nuevo torneo') }}
                </flux:button>
            </x-slot:actions>
        </x-ui.page-header>

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
</x-layouts::app>
