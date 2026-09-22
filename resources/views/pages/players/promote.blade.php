<x-layouts::app :title="__('Promover jugador')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Promover jugador')" :subtitle="$player->full_name" :eyebrow="__('Categoría actual: :category', ['category' => $team->category->name])">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ...($team->club ? [['label' => $team->club->name, 'href' => route('clubs.show', $team->club)]] : []),
                    ['label' => $team->name, 'href' => route('teams.show', $team)],
                    ['label' => __('Promover a :name', ['name' => $player->full_name])],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Ya no es permitido en esta categoría')">
            {{ __(':name ya no cumple el rango de edad de :category. Hay que elegir a qué plantel promoverlo -- deja de figurar en :team.', ['name' => $player->full_name, 'category' => $team->category->name, 'team' => $team->name]) }}
        </flux:callout>

        @if ($candidates->isEmpty())
            <x-ui.empty-state icon="exclamation-triangle" :message="__('Todavía no hay ninguna categoría más permitida disponible en este club para :name.', ['name' => $player->full_name])" />
        @else
            <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                @foreach ($candidates as $candidate)
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $candidate->category->name }}</div>
                            <div class="truncate text-xs text-zinc-500 dark:text-white/50">{{ $candidate->name }}</div>
                        </div>

                        <x-ui.confirm-delete-form
                            :action="route('teams.players.promote.store', [$team, $player])"
                            method="POST"
                            variant="warning"
                            icon="arrow-up-circle"
                            :heading="__('¿Promover a :category?', ['category' => $candidate->category->name])"
                            :description="__(':name pasará de :origin a :destination.', ['name' => $player->full_name, 'origin' => $team->category->name, 'destination' => $candidate->category->name])"
                            :confirm-label="__('Promover')"
                        >
                            <x-slot:fields>
                                <input type="hidden" name="destination_team_id" value="{{ $candidate->id }}" />
                            </x-slot:fields>

                            <flux:button variant="primary" size="sm">{{ __('Promover') }}</flux:button>
                        </x-ui.confirm-delete-form>
                    </div>
                @endforeach
            </div>
        @endif

        <flux:button :href="route('teams.show', $team)" variant="ghost" wire:navigate>{{ __('Volver') }}</flux:button>
    </div>
</x-layouts::app>
