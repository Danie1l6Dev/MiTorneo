<x-layouts::app :title="$group->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$group->name">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Mis torneos'), 'href' => route('tournaments.index')],
                    ['label' => $group->category->tournament->name, 'href' => route('tournaments.show', $group->category->tournament)],
                    ['label' => $group->category->name, 'href' => route('categories.show', $group->category)],
                    ['label' => $group->name],
                ]" />
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <flux:button :href="route('groups.edit', $group)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <form method="POST" action="{{ route('groups.destroy', $group) }}" onsubmit="return confirm('{{ __('¿Eliminar este grupo?') }}')">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        <flux:separator variant="subtle" />

        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Equipos del grupo') }}</flux:heading>

            @if ($availableTeams->isEmpty())
                <x-ui.empty-state icon="user-group" :message="__('La categoría todavía no tiene equipos registrados.')" />
            @else
                <form method="POST" action="{{ route('groups.teams.update', $group) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($availableTeams as $team)
                            <label class="hover-lift flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-white/10 glass-panel">
                                <input
                                    type="checkbox"
                                    name="team_ids[]"
                                    value="{{ $team->id }}"
                                    @checked($team->group_id === $group->id)
                                    class="rounded border-zinc-300 text-accent focus:ring-accent dark:border-white/20"
                                >
                                <span class="text-zinc-700 dark:text-white/85">{{ $team->name }}</span>

                                @if ($team->group_id && $team->group_id !== $group->id)
                                    <flux:text class="text-xs text-zinc-400">({{ $team->group->name }})</flux:text>
                                @endif
                            </label>
                        @endforeach
                    </div>

                    <flux:button type="submit" variant="primary" size="sm">{{ __('Guardar equipos') }}</flux:button>
                </form>
            @endif
        </div>
    </div>
</x-layouts::app>
