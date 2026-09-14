@php
    // One combined list -- planteles the player is already on (pre-checked,
    // locked) alongside the ones they could still join -- grouped by
    // category (and group within it) the same way the club-level
    // enrollment checkboxes already are. Mixing both into one list instead
    // of showing "already enrolled" separately from "could still add" is
    // what actually reads as "this player's planteles" at a glance -- see
    // PlayerController::edit().
    $teamsByCategory = $clubTeams
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
                        <flux:label>{{ __('Planteles de este club') }}</flux:label>

                        <template x-if="!birthDate">
                            <flux:text class="text-sm text-amber-500">
                                {{ __('Carga la fecha de nacimiento para habilitar las categorías a las que todavía puede sumarse.') }}
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
                                            $isCurrent = $currentTeams->contains('id', $team->id);
                                            $byTo = $team->category->birth_year_to;
                                            $ageDisabledExpr = $byTo === null
                                                ? '!birthDate'
                                                : "!birthDate || parseInt(birthDate.split('-')[0]) < {$byTo}";
                                            $label = $team->name;
                                            if ($groupTeams->count() > 1 || $teamsByGroup->count() > 1) {
                                                $label .= ' — '.$groupName;
                                            }
                                            if ($isCurrent) {
                                                $label .= ' ('.__('ya está').')';
                                            }
                                        @endphp

                                        {{-- An already-current team is shown
                                             checked and locked -- this form
                                             only ever ADDS a membership
                                             (syncWithoutDetaching in
                                             PlayerController::update()), it
                                             never removes one, so there's
                                             nothing to actually submit for
                                             it and unchecking it couldn't
                                             mean anything anyway. --}}
                                        {{--
                                            Blade's @if/@else can't be used
                                            inline inside a component tag's
                                            own attribute list (it gets
                                            treated as a literal attribute
                                            named "@if", not compiled) --
                                            :disabled handles the static
                                            (already-current) case and
                                            x-bind:disabled the reactive
                                            (age-gated) one; for a current
                                            team the x-bind expression is
                                            just the literal 'true', so both
                                            agree instead of fighting.
                                        --}}
                                        <flux:checkbox
                                            name="team_ids[]"
                                            value="{{ $team->id }}"
                                            label="{{ $label }}"
                                            :checked="$isCurrent || in_array($team->id, (array) old('team_ids', []))"
                                            :disabled="$isCurrent"
                                            x-bind:disabled="{{ $isCurrent ? 'true' : $ageDisabledExpr }}"
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

            @php $extraTeams = $currentTeams->reject(fn ($team) => $team->id === $player->team_id); @endphp

            {{-- Deliberately OUTSIDE the form above (nesting a second
                 <form> inside it -- what x-ui.confirm-delete-form needs for
                 its own confirm step -- isn't valid HTML) but still inside
                 the same card, right under it, since this is really just
                 another action on the same "planteles" list, not a
                 separate concern. Only an EXTRA plantel (the player_team
                 pivot) can be quit one at a time here; the primary team_id
                 isn't a pivot row and has nothing to individually detach --
                 see "Eliminar del club" below for removing the player
                 entirely. --}}
            @if ($extraTeams->isNotEmpty())
                <div class="mt-6 space-y-2 border-t border-zinc-200 pt-6 dark:border-white/10">
                    <flux:label>{{ __('Quitar de un plantel') }}</flux:label>

                    @foreach ($extraTeams as $team)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 dark:border-white/10">
                            <flux:text>{{ $team->club?->name ?? $team->name }} · {{ $team->category->name }}</flux:text>

                            <x-ui.confirm-delete-form
                                :action="route('players.teams.destroy', [$player, $team])"
                                variant="warning"
                                icon="minus-circle"
                                :heading="__('¿Quitar de este plantel?')"
                                :description="__(':name ya no va a figurar en :category. Sus goles/tarjetas ya registrados ahí no se ven afectados.', ['name' => $player->full_name, 'category' => $team->category->name])"
                                :confirm-label="__('Quitar')"
                            >
                                <flux:button variant="ghost" size="sm">{{ __('Quitar') }}</flux:button>
                            </x-ui.confirm-delete-form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($player->team->club)
            <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
                <flux:heading size="sm" class="mb-2">{{ __('Eliminar del club') }}</flux:heading>
                <flux:text class="mb-4 text-zinc-500 dark:text-white/50">
                    {{ __('Saca a :name de :club por completo, en todas sus categorías. Se bloquea si ya tiene goles, tarjetas o sanciones registradas ahí -- para ese caso, usá "Desactivar" en su lugar.', ['name' => $player->full_name, 'club' => $player->team->club->name]) }}
                </flux:text>

                <x-ui.confirm-delete-form
                    :action="route('clubs.players.destroy', [$player->team->club, $player])"
                    :heading="__('¿Eliminar a :name de :club?', ['name' => $player->full_name, 'club' => $player->team->club->name])"
                    :description="__('Esta acción no se puede deshacer.')"
                    :confirm-label="__('Eliminar del club')"
                >
                    <flux:button variant="danger" icon="trash">{{ __('Eliminar del club') }}</flux:button>
                </x-ui.confirm-delete-form>
            </div>
        @endif
    </div>
</x-layouts::app>
