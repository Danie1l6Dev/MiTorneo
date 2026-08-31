<x-layouts::app :title="$group->name">
    <div class="w-full space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:button :href="route('phases.show', $group->competitionPhase)" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
                    {{ $group->competitionPhase->name }}
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

                    @php $groupTeamIds = $group->teams->pluck('id'); @endphp

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($availableTeams as $team)
                            <label class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-white/10">
                                <input
                                    type="checkbox"
                                    name="team_ids[]"
                                    value="{{ $team->id }}"
                                    @checked($groupTeamIds->contains($team->id))
                                    class="rounded border-zinc-300 dark:border-white/20"
                                >
                                {{ $team->name }}
                            </label>
                        @endforeach
                    </div>

                    <flux:button type="submit" variant="primary" size="sm">{{ __('Guardar equipos') }}</flux:button>
                </form>
            @endif
        </div>
    </div>
</x-layouts::app>
