<x-layouts::app :title="__('Planteles de :category', ['category' => $category->name])">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Planteles de :category', ['category' => $category->name])" :subtitle="$tournament->name" />

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @if ($locked)
            <flux:callout variant="warning" icon="lock-closed" :heading="__('Planteles bloqueados')">
                {{ __('Esta categoría ya tiene una fase iniciada en este torneo -- el plantel ya quedó fijado para poder armar el calendario/cuadro sobre él, y no se puede modificar desde aquí.') }}
            </flux:callout>
        @endif

        @if ($teams->isEmpty())
            <x-ui.empty-state icon="user-group" :message="__('Todavía no hay ningún club con plantel en esta categoría.')">
                <x-slot:action>
                    <flux:button :href="route('clubs.index')" variant="primary" size="sm" wire:navigate>
                        {{ __('Ir a Clubes') }}
                    </flux:button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
                <form method="POST" action="{{ route('tournaments.global-categories.teams.update', [$tournament, $category]) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="space-y-4">
                        <flux:label>{{ __('Elige qué planteles participan en este torneo') }}</flux:label>

                        @if ($category->uses_groups)
                            @if ($groups->isEmpty())
                                <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Este torneo todavía no tiene grupos en esta categoría')">
                                    {{ __('Crea primero los grupos del torneo para poder asignar cada plantel a uno. Puedes inscribirlos ahora y asignarles el grupo después.') }}
                                    <a href="{{ route('tournaments.categories.groups.create', [$tournament, $category]) }}" wire:navigate class="underline">{{ __('Crear un grupo') }}</a>
                                </flux:callout>
                            @else
                                <flux:text class="text-sm text-zinc-500 dark:text-white/60">
                                    {{ __('El grupo se elige aquí, para este torneo: el mismo plantel puede estar en otro grupo en otro torneo.') }}
                                </flux:text>
                            @endif
                        @endif

                        <div class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-white/5 dark:border-white/10">
                            @foreach ($teams as $team)
                                @php
                                    $isSelected = in_array($team->id, old('team_ids', $selectedIds));
                                    $currentGroup = old('groups.'.$team->id, $groupByTeam[$team->id] ?? '');
                                @endphp

                                <div class="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5" x-data="{ on: {{ $isSelected ? 'true' : 'false' }} }">
                                    <label class="flex min-w-0 items-center gap-2.5 text-sm text-zinc-800 dark:text-white" :class="{{ $locked ? 'true' : 'false' }} ? 'opacity-60' : 'cursor-pointer'">
                                        <input
                                            type="checkbox"
                                            name="team_ids[]"
                                            value="{{ $team->id }}"
                                            x-model="on"
                                            @checked($isSelected)
                                            @disabled($locked)
                                            class="rounded border-zinc-300"
                                        >
                                        <span class="truncate">{{ $team->club->name }}{{ $team->club->name !== $team->name ? ' — '.$team->name : '' }}</span>
                                    </label>

                                    @if ($category->uses_groups && $groups->isNotEmpty())
                                        <select
                                            name="groups[{{ $team->id }}]"
                                            x-show="on"
                                            x-cloak
                                            @disabled($locked)
                                            class="rounded-lg border border-zinc-200 bg-white px-2 py-1 text-sm text-zinc-800 dark:border-white/10 dark:bg-zinc-800 dark:text-white"
                                        >
                                            <option value="">{{ __('Sin grupo') }}</option>
                                            @foreach ($groups as $group)
                                                <option value="{{ $group->id }}" @selected((string) $currentGroup === (string) $group->id)>{{ $group->name }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @error('team_ids')
                            <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                        @enderror

                        @error('groups.*')
                            <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                        @enderror
                    </div>

                    <div class="flex items-center gap-3">
                        @unless ($locked)
                            <flux:button type="submit" variant="primary">{{ __('Guardar planteles') }}</flux:button>
                        @endunless
                        <flux:button :href="route('tournaments.categories.show', [$tournament, $category])" variant="ghost" wire:navigate>{{ $locked ? __('Volver') : __('Cancelar') }}</flux:button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-layouts::app>
