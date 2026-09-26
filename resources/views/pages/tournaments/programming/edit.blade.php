<x-layouts::app :title="__('Programar fecha')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Programar fecha')" :subtitle="__('Asigna día, cancha y horas a los partidos pendientes de una fecha, categoría por categoría.')">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => $tournament->name, 'href' => route('tournaments.show', $tournament)],
                    ['label' => __('Programar fecha')],
                ]" />
            </x-slot:breadcrumbs>

            @php
                $totalPending = collect($catalog)->sum('total');
                $totalReady = collect($catalog)->sum('ready');
            @endphp

            @if ($catalog !== [])
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <x-ui.stat-pill icon="calendar-days" :value="count($catalog)" :label="trans_choice('fecha pendiente|fechas pendientes', count($catalog))" color="cyan" />
                    <x-ui.stat-pill icon="clock" :value="$totalPending - $totalReady" :label="trans_choice('partido por programar|partidos por programar', $totalPending - $totalReady)" color="amber" />
                    <x-ui.stat-pill icon="check-circle" :value="$totalReady" :label="trans_choice('partido listo|partidos listos', $totalReady)" color="green" />
                </div>
            @endif
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Revisa los datos ingresados')">
                <ul class="list-disc space-y-1 ps-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </flux:callout>
        @endif

        @if ($catalog === [])
            <x-ui.empty-state icon="calendar-days" :message="__('No hay partidos pendientes por programar en este torneo.')" />
        @else
            {{-- The whole catalog (every fecha, its categories and their pending matches)
                 arrives once, as JSON, and Alpine filters it right here: picking a fecha
                 or a category is instant and never reloads the page. Only the proposal
                 panel asks the server (TournamentProgrammingController::preview()),
                 because clashes need the database. --}}
            <div
                class="space-y-8"
                x-data="{
                    catalog: {{ \Illuminate\Support\Js::from($catalog) }},
                    round: {{ \Illuminate\Support\Js::from($initial['round']) }},
                    categoryId: {{ \Illuminate\Support\Js::from($initial['category']) }},
                    overwrite: {{ \Illuminate\Support\Js::from($initial['overwrite']) }},
                    ready: false,
                    loading: false,
                    request: null,
                    timer: null,
                    get roundData() {
                        return this.catalog.find((item) => item.number === this.round) ?? null;
                    },
                    get category() {
                        return this.roundData?.categories.find((item) => item.id === this.categoryId) ?? null;
                    },
                    get matches() {
                        return (this.category?.matches ?? []).filter((match) => this.overwrite || ! match.has_day);
                    },
                    percent(item) {
                        return item.total > 0 ? Math.round(item.ready / item.total * 100) : 0;
                    },
                    isDone(item) {
                        return item.total > 0 && item.ready >= item.total;
                    },
                    count(amount, one, many) {
                        return amount + ' ' + (amount === 1 ? one : many);
                    },
                    pickRound(number) {
                        this.round = number;
                        this.categoryId = null;
                        this.ready = false;
                        this.sync();
                    },
                    pickCategory(id) {
                        this.categoryId = id;
                        this.ready = false;
                        this.sync();
                        this.$nextTick(() => this.refresh());
                    },
                    toggleOverwrite() {
                        this.ready = false;
                        this.sync();
                        this.$nextTick(() => this.refresh());
                    },
                    // Keeps the address bar on the current selection (no navigation, no history entry).
                    sync() {
                        const url = new URL(window.location.href);
                        url.search = '';
                        if (this.round !== null) url.searchParams.set('round', this.round);
                        if (this.categoryId !== null) url.searchParams.set('category', this.categoryId);
                        if (this.overwrite) url.searchParams.set('overwrite', 1);
                        history.replaceState(null, '', url);
                    },
                    // Typing in the four fields: wait a moment, then ask for a new proposal.
                    queue() {
                        clearTimeout(this.timer);
                        this.timer = setTimeout(() => this.refresh(), 400);
                    },
                    // Adjusting one match by hand: wait a moment, then re-check the clashes
                    // keeping every value as typed.
                    queueMatches() {
                        clearTimeout(this.timer);
                        this.timer = setTimeout(() => this.refresh('matches'), 400);
                    },
                    useStart(time) {
                        this.$refs.form.elements['config[start]'].value = time;
                        this.refresh();
                    },
                    async refresh(mode = 'config') {
                        if (this.category === null || this.matches.length === 0) return;

                        // The panel is swapped as a whole, so remember which field had the focus.
                        const focused = document.activeElement?.name ?? null;

                        this.request?.abort();
                        const request = new AbortController();
                        this.request = request;
                        this.loading = true;

                        try {
                            const body = new FormData(this.$refs.form);
                            body.set('mode', mode);

                            const response = await fetch({{ \Illuminate\Support\Js::from(route('tournaments.programming.preview', $tournament)) }}, {
                                method: 'POST',
                                body,
                                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                                signal: request.signal,
                            });

                            if (response.ok) {
                                this.$refs.preview.innerHTML = await response.text();
                                this.ready = true;

                                if (focused !== null) {
                                    [...this.$refs.preview.querySelectorAll('input, select')].find((field) => field.name === focused)?.focus();
                                }
                            }
                        } catch (error) {
                            if (error.name !== 'AbortError') console.error(error);
                        } finally {
                            if (this.request === request) this.loading = false;
                        }
                    },
                }"
                x-init="if (category) $nextTick(() => refresh())"
            >
                <div class="space-y-4">
                    <div class="space-y-1">
                        <flux:heading size="lg">{{ __('Fecha a programar') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500 dark:text-white/60">
                            {{ __('La barra muestra cuántos partidos de cada fecha ya están listos: con día asignado o ya jugados.') }}
                        </flux:text>
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                        <template x-for="item in catalog" :key="item.number">
                            <button
                                type="button"
                                x-on:click="pickRound(item.number)"
                                :aria-current="round === item.number ? 'true' : null"
                                class="hover-lift group block rounded-2xl border p-4 text-left glass-panel transition-colors"
                                :class="round === item.number ? 'border-green-500/60 bg-green-500/10 ring-2 ring-green-500/30' : 'border-zinc-200 dark:border-white/10'"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <span class="sr-only" x-text="'{{ __('Fecha') }} ' + item.number"></span>
                                        <div class="text-[10px] font-semibold uppercase tracking-widest text-zinc-400 dark:text-white/40" aria-hidden="true">{{ __('Fecha') }}</div>
                                        <div class="font-display text-3xl font-bold leading-none tabular-nums text-zinc-900 dark:text-white" aria-hidden="true" x-text="item.number"></div>
                                    </div>

                                    <span x-show="isDone(item)" x-cloak>
                                        <flux:icon.check-circle variant="mini" class="size-5 text-green-500" />
                                    </span>
                                </div>

                                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10">
                                    <div class="h-full rounded-full bg-green-500 transition-all duration-500" :style="'width: ' + percent(item) + '%'"></div>
                                </div>

                                <div class="mt-2 flex items-center justify-between gap-2 text-[11px] text-zinc-500 dark:text-white/50">
                                    <span x-text="count(item.total, '{{ __('partido') }}', '{{ __('partidos') }}')"></span>
                                    <span
                                        :class="isDone(item) ? 'font-semibold text-green-600 dark:text-green-400' : ''"
                                        x-text="isDone(item) ? '{{ __('Lista') }}' : count(item.ready, '{{ __('listo') }}', '{{ __('listos') }}')"
                                    ></span>
                                </div>
                            </button>
                        </template>
                    </div>
                </div>

                <template x-if="roundData === null">
                    <div class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-white/15">
                        <flux:icon.cursor-arrow-rays variant="outline" class="size-6 text-zinc-400 dark:text-white/40" />
                        <flux:text class="text-zinc-500 dark:text-white/60">{{ __('Elige una fecha para empezar.') }}</flux:text>
                    </div>
                </template>

                <div x-show="roundData !== null" x-cloak class="space-y-8">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <flux:heading size="lg" x-text="roundData?.title"></flux:heading>

                        <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-600 dark:text-white/70">
                            <input type="checkbox" x-model="overwrite" x-on:change="toggleOverwrite()" class="rounded border-zinc-300">
                            {{ __('Incluir partidos que ya tienen fecha') }}
                        </label>
                    </div>

                    <div class="space-y-4">
                        <div class="space-y-1">
                            <flux:heading size="sm">{{ __('Categoría') }}</flux:heading>
                            <flux:text class="text-sm text-zinc-500 dark:text-white/60">
                                {{ __('Programa una categoría a la vez: verás solo sus partidos de esta fecha.') }}
                            </flux:text>
                        </div>

                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            <template x-for="item in roundData?.categories ?? []" :key="item.id">
                                <button
                                    type="button"
                                    x-on:click="pickCategory(item.id)"
                                    :aria-current="categoryId === item.id ? 'true' : null"
                                    class="hover-lift group block rounded-2xl border p-4 text-left glass-panel transition-colors"
                                    :class="categoryId === item.id ? 'border-green-500/60 bg-green-500/10 ring-2 ring-green-500/30' : 'border-zinc-200 dark:border-white/10'"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="font-display text-base font-bold uppercase leading-tight text-zinc-900 dark:text-white" x-text="item.name"></div>

                                        <span x-show="isDone(item)" x-cloak>
                                            <flux:icon.check-circle variant="mini" class="size-5 shrink-0 text-green-500" />
                                        </span>
                                    </div>

                                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10">
                                        <div class="h-full rounded-full bg-green-500 transition-all duration-500" :style="'width: ' + percent(item) + '%'"></div>
                                    </div>

                                    <div class="mt-2 flex items-center justify-between gap-2 text-[11px] text-zinc-500 dark:text-white/50">
                                        <span x-text="count(item.total, '{{ __('partido') }}', '{{ __('partidos') }}')"></span>
                                        <span
                                            :class="isDone(item) ? 'font-semibold text-green-600 dark:text-green-400' : ''"
                                            x-text="isDone(item) ? '{{ __('Lista') }}' : count(item.ready, '{{ __('listo') }}', '{{ __('listos') }}')"
                                        ></span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <template x-if="category === null">
                        <div class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-white/15">
                            <flux:icon.cursor-arrow-rays variant="outline" class="size-6 text-zinc-400 dark:text-white/40" />
                            <flux:text class="text-zinc-500 dark:text-white/60">{{ __('Elige una categoría para ver sus partidos.') }}</flux:text>
                        </div>
                    </template>

                    <div x-show="category !== null && matches.length === 0" x-cloak>
                        <flux:callout variant="success" icon="check-circle" :heading="__('Todos los partidos pendientes de esta categoría ya tienen día asignado.')">
                            {{ __('Activa «Incluir partidos que ya tienen fecha» si quieres volver a programarlos.') }}
                        </flux:callout>
                    </div>

                    <div x-show="category !== null && matches.length > 0" x-cloak class="space-y-6">
                        @if ($venues->isEmpty())
                            <flux:callout variant="warning" icon="map-pin" :heading="__('Aún no tienes canchas registradas.')">
                                <a href="{{ route('venues.create') }}" wire:navigate class="underline">{{ __('Registra una cancha') }}</a>
                                {{ __('para poder asignarla. También puedes programar solo el día y la hora.') }}
                            </flux:callout>
                        @endif

                        {{-- Any change to the four fields asks the server for a fresh proposal;
                             Enter is blocked so it can't submit before there is one. --}}
                        <form method="POST" action="{{ route('tournaments.programming.store', $tournament) }}" class="space-y-6" x-ref="form">
                            @csrf
                            <input type="hidden" name="round" :value="round">
                            <input type="hidden" name="category" :value="categoryId">
                            <input type="hidden" name="overwrite" :value="overwrite ? 1 : 0">

                            <div
                                class="grid gap-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel sm:grid-cols-2 lg:grid-cols-4"
                                x-on:input="queue()"
                                x-on:change="queue()"
                                x-on:keydown.enter.prevent
                            >
                                <flux:input
                                    name="config[date]"
                                    type="date"
                                    label="{{ __('Día') }}"
                                    value="{{ $config['date'] ?? '' }}"
                                />

                                <flux:select name="config[venue_id]" label="{{ __('Cancha') }}" placeholder="{{ __('Sin cancha') }}">
                                    @foreach ($venues as $venue)
                                        <flux:select.option value="{{ $venue->id }}" :selected="(string) $venue->id === (string) ($config['venue_id'] ?? '')">
                                            {{ $venue->name }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>

                                <flux:input
                                    name="config[start]"
                                    type="time"
                                    label="{{ __('Primera hora (opcional)') }}"
                                    value="{{ $config['start'] ?? '' }}"
                                />

                                <flux:input
                                    name="config[rest]"
                                    type="number"
                                    label="{{ __('Descanso entre partidos (min)') }}"
                                    value="{{ $config['rest'] ?? '' }}"
                                    min="0"
                                    max="120"
                                    placeholder="0"
                                />
                            </div>

                            {{-- Until the server's proposal arrives (a moment after picking a
                                 category) this is the category's match list, straight from the
                                 catalog already in the browser. --}}
                            <div x-show="! ready" class="divide-y divide-zinc-100 rounded-2xl border border-zinc-200 px-5 dark:divide-white/5 dark:border-white/10 glass-panel">
                                <template x-for="match in matches" :key="match.id">
                                    <div class="flex flex-wrap items-center gap-2 py-3 text-sm font-medium text-zinc-800 dark:text-white">
                                        <span x-text="match.home"></span>
                                        <span class="text-zinc-400 dark:text-white/40">vs</span>
                                        <span x-text="match.away"></span>
                                        <span x-show="match.group" class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] font-semibold text-zinc-600 dark:bg-white/10 dark:text-white/60" x-text="match.group"></span>
                                        <span x-show="match.current" class="w-full text-xs font-normal text-zinc-500 dark:text-white/50" x-text="'{{ __('Actualmente') }}: ' + match.current"></span>
                                    </div>
                                </template>
                            </div>

                            <div
                                x-ref="preview"
                                x-show="ready"
                                class="transition-opacity duration-200"
                                :class="loading ? 'opacity-50' : ''"
                                x-on:input="queueMatches()"
                                x-on:change="queueMatches()"
                            ></div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-layouts::app>
