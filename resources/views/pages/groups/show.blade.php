<x-layouts::app :title="$group->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$group->name">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Mis torneos'), 'href' => route('dashboard')],
                    ['label' => $group->category->tournament->name, 'href' => route('tournaments.show', $group->category->tournament)],
                    ['label' => $group->category->name, 'href' => route('categories.show', $group->category)],
                    ['label' => $group->name],
                ]" />
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <flux:button :href="route('groups.edit', $group)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <x-ui.confirm-delete-form
                    :action="route('groups.destroy', $group)"
                    :heading="__('¿Eliminar este grupo?')"
                >
                    <flux:button variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </x-ui.confirm-delete-form>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        <flux:separator variant="subtle" />

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Equipos del grupo') }}</flux:heading>

                <flux:button
                    :href="route('categories.teams.create', ['category' => $group->category, 'group' => $group->id])"
                    variant="primary"
                    size="sm"
                    icon="plus"
                    wire:navigate
                >
                    {{ __('Nuevo equipo') }}
                </flux:button>
            </div>

            @if ($group->teams->isEmpty())
                <x-ui.empty-state icon="user-group" :message="__('Todavía no hay equipos en este grupo.')" />
            @else
                <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                    @foreach ($group->teams->sortBy('name') as $team)
                        <x-ui.team-row :team="$team">
                            <x-slot:actions>
                                <x-ui.confirm-delete-form
                                    :action="route('groups.teams.detach', [$group, $team])"
                                    :heading="__('¿Quitar :name de este grupo?', ['name' => $team->name])"
                                    :confirm-label="__('Quitar')"
                                    icon="user-minus"
                                >
                                    <flux:tooltip :content="__('Quitar del grupo')">
                                        <flux:button variant="ghost" size="sm" icon="x-mark" />
                                    </flux:tooltip>
                                </x-ui.confirm-delete-form>
                            </x-slot:actions>
                        </x-ui.team-row>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($unassignedTeams->isNotEmpty())
            <flux:separator variant="subtle" />

            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Agregar equipo existente') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Solo se muestran los equipos de la categoría que todavía no están en ningún grupo.') }}</flux:text>

                <form method="POST" action="{{ route('groups.teams.attach', $group) }}" class="flex flex-wrap items-end gap-3">
                    @csrf

                    <flux:select name="team_id" class="max-w-xs" placeholder="{{ __('Selecciona un equipo') }}">
                        @foreach ($unassignedTeams as $team)
                            <flux:select.option value="{{ $team->id }}">{{ $team->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:button type="submit" variant="primary" size="sm">{{ __('Agregar al grupo') }}</flux:button>
                </form>
            </div>
        @endif
    </div>
</x-layouts::app>
