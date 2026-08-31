<x-layouts::app :title="$group->name">
    <div class="w-full space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:button :href="route('categories.show', $group->category)" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
                    {{ $group->category->name }}
                </flux:button>

                <flux:heading size="xl" class="mt-2">{{ $group->name }}</flux:heading>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <flux:button :href="route('groups.edit', $group)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <form method="POST" action="{{ route('groups.destroy', $group) }}" onsubmit="return confirm('{{ __('¿Eliminar este grupo?') }}')">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </form>
            </div>
        </div>

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        <flux:separator />

        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Equipos del grupo') }}</flux:heading>

            @if ($availableTeams->isEmpty())
                <flux:text class="text-zinc-500">
                    {{ __('La categoría todavía no tiene equipos registrados.') }}
                </flux:text>
            @else
                <form method="POST" action="{{ route('groups.teams.update', $group) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($availableTeams as $team)
                            <label class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-white/10">
                                <input
                                    type="checkbox"
                                    name="team_ids[]"
                                    value="{{ $team->id }}"
                                    @checked($team->group_id === $group->id)
                                    class="rounded border-zinc-300 dark:border-white/20"
                                >
                                {{ $team->name }}

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
