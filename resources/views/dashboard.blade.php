<x-layouts::app :title="__('Mis torneos')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header
            :eyebrow="__('Panel de control')"
            :title="__('Mis torneos')"
            :subtitle="__('Bienvenido, :name.', ['name' => auth()->user()->name])"
        >
            <x-slot:actions>
                <flux:button :href="route('tournaments.create')" variant="primary" icon="plus" wire:navigate>
                    {{ __('Nuevo torneo') }}
                </flux:button>
            </x-slot:actions>
        </x-ui.page-header>

        <div class="mx-auto grid grid-cols-2 gap-4 sm:grid-cols-5 sm:gap-5 lg:max-w-4xl">
            <x-ui.stat-card :label="__('Tus torneos')" :value="$tournamentsCount" icon="trophy" color="green" />
            <x-ui.stat-card :label="__('Categorías')" :value="$tournaments->sum('global_categories_count')" icon="rectangle-stack" color="cyan" />
            <x-ui.stat-card :label="__('Clubes')" :value="$tournaments->sum('global_clubs_count')" icon="shield-check" color="amber" />
            <x-ui.stat-card :label="__('Planteles')" :value="$tournaments->sum('global_teams_count')" icon="user-group" color="cyan" />
            <x-ui.stat-card :label="__('Partidos')" :value="$tournaments->sum('matches_count')" icon="calendar-days" color="accent" />
        </div>

        <div class="space-y-4">
            @if ($tournaments->isEmpty())
                <x-ui.empty-state icon="trophy" :message="__('Todavía no has creado ningún torneo.')">
                    <x-slot:action>
                        <flux:button :href="route('tournaments.create')" variant="primary" size="sm" icon="plus" wire:navigate>
                            {{ __('Crear torneo') }}
                        </flux:button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <div class="flex flex-wrap justify-center gap-5">
                    @foreach ($tournaments as $tournament)
                        <x-ui.entity-card
                            :href="route('tournaments.show', $tournament)"
                            :title="$tournament->name"
                            icon="trophy"
                            color="green"
                            size="lg"
                            :stats="[
                                trans_choice(':count categoría|:count categorías', $tournament->global_categories_count, ['count' => $tournament->global_categories_count]),
                                trans_choice(':count club|:count clubes', $tournament->global_clubs_count, ['count' => $tournament->global_clubs_count]),
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
