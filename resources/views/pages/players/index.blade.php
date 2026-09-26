<x-layouts::app :title="__('Buscar jugador')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Buscar jugador')" :subtitle="__('Busca un jugador registrado por documento, nombre o apellido, o por un club en el que haya jugado. Al abrirlo verás toda su ficha.')" />

        @if ($catalog === [])
            <x-ui.empty-state icon="magnifying-glass" :message="__('Todavía no hay jugadores registrados en ningún club.')" />
        @else
            {{-- Every player is sent once, as JSON, and filtered right here as the text
                 changes: no requests, no reloads. Each entry carries a haystack (name,
                 document, current and past clubs) already lowercased and without accents;
                 what is typed gets the same treatment, is split into words, and every
                 word has to appear -- so "perez juan", "juan perez" and "1234" all work. --}}
            <div
                class="space-y-6"
                x-data="{
                    players: {{ \Illuminate\Support\Js::from($catalog) }},
                    query: new URLSearchParams(window.location.search).get('q') ?? '',
                    limit: 50,
                    normalize(text) {
                        return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
                    },
                    get words() {
                        return this.normalize(this.query).split(/\s+/).filter(Boolean);
                    },
                    get results() {
                        if (this.words.length === 0) return [];

                        return this.players.filter((player) => this.words.every((word) => player.haystack.includes(word)));
                    },
                }"
                x-init="$watch('query', (value) => {
                    const url = new URL(window.location.href);
                    value.trim() === '' ? url.searchParams.delete('q') : url.searchParams.set('q', value);
                    history.replaceState(null, '', url);
                })"
            >
                <div class="mx-auto w-full max-w-xl">
                    <flux:input
                        type="search"
                        x-model="query"
                        icon="magnifying-glass"
                        autofocus
                        :placeholder="__('Documento, nombre, apellido o club...')"
                    />
                </div>

                <div x-show="words.length === 0" x-cloak>
                    <x-ui.empty-state icon="magnifying-glass" :message="__('Escribe un documento, un nombre o un club para buscar.')" />
                </div>

                <div x-show="words.length > 0 && results.length === 0" x-cloak>
                    <x-ui.empty-state icon="magnifying-glass" :message="__('No se encontró ningún jugador con esa búsqueda.')" />
                </div>

                <div x-show="results.length > 0" x-cloak class="mx-auto w-full max-w-3xl space-y-3">
                    <flux:text class="text-sm text-zinc-500 dark:text-white/60" x-text="results.length > limit ? '{{ __('Mostrando los primeros') }} ' + limit + ' {{ __('de') }} ' + results.length + '. {{ __('Escribe más para acotar.') }}' : (results.length === 1 ? '1 {{ __('resultado') }}' : results.length + ' {{ __('resultados') }}')"></flux:text>

                    <template x-for="player in results.slice(0, limit)" :key="player.id">
                        <a
                            :href="player.url"
                            wire:navigate
                            class="hover-lift block overflow-hidden rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <flux:heading size="lg" class="truncate" x-text="player.name"></flux:heading>
                                    <flux:text class="text-zinc-500 dark:text-white/50" x-show="player.document" x-text="'{{ __('Documento') }}: ' + player.document"></flux:text>
                                </div>

                                <div class="flex items-center gap-2">
                                    <span x-show="player.inactive" x-cloak class="rounded-md bg-zinc-200 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-white/10 dark:text-white/60">{{ __('Inactivo') }}</span>
                                    <flux:icon.chevron-right variant="micro" class="size-4 text-zinc-400" />
                                </div>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <template x-for="team in player.teams" :key="team.club + team.category">
                                    <span class="rounded-md bg-cyan-500/15 px-2 py-0.5 text-xs font-medium text-cyan-700 dark:text-cyan-300" x-text="team.club + ' · ' + team.category"></span>
                                </template>

                                <span x-show="player.teams.length === 0" class="rounded-md bg-zinc-200 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-white/10 dark:text-white/60">{{ __('Sin plantel') }}</span>
                            </div>

                            <div class="mt-2 text-xs text-zinc-500 dark:text-white/50" x-show="player.past_clubs.length > 0">
                                {{ __('Antes también con') }}: <span x-text="player.past_clubs.join(', ')"></span>
                            </div>
                        </a>
                    </template>
                </div>
            </div>
        @endif
    </div>
</x-layouts::app>
