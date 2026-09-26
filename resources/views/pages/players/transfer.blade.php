<x-layouts::app :title="__('Transferir jugador')">
    <div class="mx-auto w-full max-w-3xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Transferir jugador')" :subtitle="$player->full_name">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Buscar jugador'), 'href' => route('players.index')],
                    ['label' => $player->full_name, 'href' => route('players.show', $player)],
                    ['label' => __('Transferir')],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Revisa los datos ingresados')">
                <ul class="list-disc space-y-1 ps-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </flux:callout>
        @endif

        <div class="rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
            <flux:heading size="sm" class="mb-2">{{ __('Ahora juega en') }}</flux:heading>

            <div class="flex flex-wrap gap-2">
                @forelse ($currentTeams as $team)
                    <flux:badge size="sm" color="green" icon="shield-check">
                        {{ $team->club?->name ?? $team->name }} · {{ $team->category->name }}
                    </flux:badge>
                @empty
                    <flux:badge size="sm" color="zinc">{{ __('Sin plantel') }}</flux:badge>
                @endforelse
            </div>

            <flux:text class="mt-3 text-xs text-zinc-500 dark:text-white/50">
                {{ __('Al transferirlo, sus planteles actuales se cierran con la fecha que indiques y quedan en su historial. El jugador queda activo en el club nuevo.') }}
            </flux:text>
        </div>

        @if ($player->birth_date === null)
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Falta la fecha de nacimiento')">
                {{ __('Hace falta para saber en qué categorías puede jugar en el club nuevo.') }}
                <a href="{{ route('players.edit', $player) }}" wire:navigate class="underline">{{ __('Completarla en su ficha') }}</a>
            </flux:callout>
        @elseif ($clubs === [])
            <x-ui.empty-state icon="shield-check" :message="__('No tienes otro club al que transferirlo. Crea primero el club de destino.')" />
        @else
            {{-- The clubs and their planteles arrive with the page; choosing a club shows
                 its planteles right away, no request. --}}
            <form
                method="POST"
                action="{{ route('players.transfer.store', $player) }}"
                class="space-y-6"
                x-data="{
                    clubs: {{ \Illuminate\Support\Js::from($clubs) }},
                    clubId: {{ \Illuminate\Support\Js::from((string) old('club_id', '')) }},
                    selected: {{ \Illuminate\Support\Js::from(array_map('strval', (array) old('team_ids', []))) }},
                    search: '',
                    get club() {
                        return this.clubs.find((club) => String(club.id) === String(this.clubId)) ?? null;
                    },
                    normalize(text) {
                        return String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
                    },
                    get filteredClubs() {
                        const query = this.normalize(this.search);

                        return query === '' ? this.clubs : this.clubs.filter((club) => this.normalize(club.name).includes(query));
                    },
                    pickClub(id) {
                        this.clubId = String(id);
                        this.selected = [];
                        this.search = '';
                    },
                    clearClub() {
                        this.clubId = '';
                        this.selected = [];
                    },
                }"
            >
                @csrf

                <div class="space-y-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                    <flux:field>
                        <flux:label>{{ __('Club de destino') }}</flux:label>

                        <input type="hidden" name="club_id" :value="clubId">

                        {{-- Chosen: shows the club with a way back to the search. --}}
                        <div x-show="club !== null" x-cloak class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5">
                            <span class="text-sm font-medium text-zinc-800 dark:text-white" x-text="club?.name"></span>
                            <button type="button" x-on:click="clearClub()" class="text-xs font-medium text-accent-content hover:underline">{{ __('Cambiar') }}</button>
                        </div>

                        {{-- Not chosen yet: a search box that filters the clubs as you type. --}}
                        <div x-show="club === null" class="space-y-2">
                            <flux:input
                                type="search"
                                icon="magnifying-glass"
                                x-model="search"
                                placeholder="{{ __('Buscar club...') }}"
                                autocomplete="off"
                            />

                            <div class="max-h-56 overflow-y-auto rounded-lg border border-zinc-200 dark:border-white/10">
                                <template x-for="item in filteredClubs" :key="item.id">
                                    <button
                                        type="button"
                                        x-on:click="pickClub(item.id)"
                                        class="block w-full border-b border-zinc-100 px-3 py-2 text-start text-sm text-zinc-800 last:border-b-0 hover:bg-zinc-50 dark:border-white/5 dark:text-white dark:hover:bg-white/10"
                                        x-text="item.name"
                                    ></button>
                                </template>

                                <div x-show="filteredClubs.length === 0" x-cloak class="px-3 py-3 text-sm text-zinc-500 dark:text-white/60">
                                    {{ __('Ningún club coincide con la búsqueda.') }}
                                </div>
                            </div>
                        </div>

                        <flux:error name="club_id" />
                    </flux:field>

                    <div x-show="club !== null" x-cloak class="space-y-2">
                        <flux:label>{{ __('Planteles en el club nuevo') }}</flux:label>

                        <template x-if="club !== null && club.teams.length === 0">
                            <flux:text class="text-zinc-500 dark:text-white/60">{{ __('Ese club todavía no tiene planteles.') }}</flux:text>
                        </template>

                        <div class="space-y-1.5">
                            <template x-for="team in club?.teams ?? []" :key="team.id">
                                <label class="flex items-center gap-2.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-white/10" :class="team.eligible ? 'cursor-pointer' : 'opacity-50'">
                                    <input type="checkbox" name="team_ids[]" :value="team.id" :disabled="! team.eligible" x-model="selected" class="rounded border-zinc-300">
                                    <span class="font-medium text-zinc-800 dark:text-white" x-text="team.category"></span>
                                    <span x-show="team.group" class="text-xs text-zinc-500 dark:text-white/50" x-text="team.group"></span>
                                    <span x-show="! team.eligible" class="ms-auto text-xs text-amber-600 dark:text-amber-400">{{ __('No cumple la edad') }}</span>
                                </label>
                            </template>
                        </div>

                        <flux:text class="text-xs text-zinc-500 dark:text-white/50">
                            {{ __('El de la categoría más pequeña que marques será su plantel principal; los demás, planteles adicionales.') }}
                        </flux:text>
                    </div>
                </div>

                <div class="grid gap-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel sm:grid-cols-2">
                    <flux:input
                        name="date"
                        type="date"
                        label="{{ __('Fecha de la transferencia') }}"
                        value="{{ old('date', $today) }}"
                        max="{{ $today }}"
                        required
                    />

                    <flux:input
                        name="jersey_number"
                        type="number"
                        label="{{ __('Dorsal en el club nuevo (opcional)') }}"
                        value="{{ old('jersey_number', $player->jersey_number) }}"
                        min="1"
                        max="99"
                    />

                    <div class="sm:col-span-2">
                        <flux:textarea name="notes" label="{{ __('Motivo (opcional)') }}" rows="2" maxlength="500">{{ old('notes') }}</flux:textarea>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary" icon="arrows-right-left" x-bind:class="selected.length === 0 ? 'cursor-not-allowed opacity-50' : ''" x-on:click="if (selected.length === 0) $event.preventDefault()">
                        {{ __('Transferir jugador') }}
                    </flux:button>
                    <flux:button :href="route('players.show', $player)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        @endif
    </div>
</x-layouts::app>
