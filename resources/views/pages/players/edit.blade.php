@php
    $teamsByCategory = $candidateTeams
        ->groupBy('category.name')
        ->map(fn ($teams) => $teams->groupBy(fn ($team) => $team->group->name ?? __('Sin grupo')));
@endphp

<x-layouts::app :title="__('Editar jugador')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up" x-data="{ birthDate: '{{ old('birth_date', optional($player->birth_date)->format('Y-m-d')) }}' }">
        <x-ui.page-header :title="__('Editar jugador')" :subtitle="$player->team->name" />

        @unless ($player->birth_date)
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Falta la fecha de nacimiento')">
                {{ __('Sin este dato no se puede sumar a este jugador a ningún otro plantel/categoría. Cárgala acá abajo para habilitar las opciones.') }}
            </flux:callout>
        @endunless

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('players.update', $player) }}" class="space-y-6">
                @csrf
                @method('PUT')

                @include('pages.players._fields')

                @if ($teamsByCategory->isNotEmpty())
                    <div class="space-y-4">
                        <flux:label>{{ __('Sumarlo también a otro plantel de este club (opcional)') }}</flux:label>

                        <template x-if="!birthDate">
                            <flux:text class="text-sm text-amber-500">
                                {{ __('Carga la fecha de nacimiento para habilitar las categorías correspondientes.') }}
                            </flux:text>
                        </template>

                        @foreach ($teamsByCategory as $categoryName => $teamsByGroup)
                            <div class="space-y-1 rounded-xl border border-zinc-200 p-4 dark:border-white/10">
                                <div class="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                    {{ $categoryName }}
                                </div>

                                @foreach ($teamsByGroup as $groupName => $groupTeams)
                                    @foreach ($groupTeams as $team)
                                        @php
                                            $byTo = $team->category->birth_year_to;
                                            $disabledExpr = $byTo === null
                                                ? '!birthDate'
                                                : "!birthDate || parseInt(birthDate.split('-')[0]) < {$byTo}";
                                            $label = $team->name;
                                            if ($groupTeams->count() > 1 || $teamsByGroup->count() > 1) {
                                                $label .= ' — '.$groupName;
                                            }
                                        @endphp

                                        <flux:checkbox
                                            name="team_ids[]"
                                            value="{{ $team->id }}"
                                            label="{{ $label }}"
                                            x-bind:disabled="{{ $disabledExpr }}"
                                            :checked="in_array($team->id, (array) old('team_ids', []))"
                                        />
                                    @endforeach
                                @endforeach
                            </div>
                        @endforeach

                        @error('team_ids')
                            <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                        @enderror
                    </div>
                @endif

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                    <flux:button :href="route('teams.show', $player->team)" variant="ghost" wire:navigate>
                        {{ __('Cancelar') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
