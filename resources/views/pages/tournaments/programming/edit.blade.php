<x-layouts::app :title="__('Programar fecha')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Programar fecha')" :subtitle="__('Asigna día, cancha y horas a los partidos pendientes de una fecha, categoría por categoría.')">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => $tournament->name, 'href' => route('tournaments.show', $tournament)],
                    ['label' => __('Programar fecha')],
                ]" />
            </x-slot:breadcrumbs>
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

        @if ($rounds === [])
            <x-ui.empty-state icon="calendar-days" :message="__('No hay partidos pendientes por programar en este torneo.')" />
        @else
            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Fecha a programar') }}</flux:heading>

                <div class="flex flex-wrap gap-2">
                    @foreach ($rounds as $number)
                        <flux:button
                            :href="route('tournaments.programming.edit', [$tournament, 'round' => $number, 'overwrite' => $overwrite ? 1 : null])"
                            size="sm"
                            :variant="$number === $round ? 'primary' : 'ghost'"
                            wire:navigate
                        >
                            {{ __('Fecha :number', ['number' => $number]) }}
                        </flux:button>
                    @endforeach
                </div>
            </div>

            @if ($round !== null)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:heading size="lg">{{ $roundTitle }}</flux:heading>

                    {{-- A separate GET form: toggling it reloads the page with a different
                         candidate list (matches that already have a day are left out by
                         default so a second pass never undoes the first). --}}
                    <form method="GET" action="{{ route('tournaments.programming.edit', $tournament) }}">
                        <input type="hidden" name="round" value="{{ $round }}">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-600 dark:text-white/70">
                            <input type="checkbox" name="overwrite" value="1" @checked($overwrite) onchange="this.form.submit()" class="rounded border-zinc-300">
                            {{ __('Incluir partidos que ya tienen fecha') }}
                        </label>
                    </form>
                </div>

                @if ($allScheduled)
                    <flux:callout variant="success" icon="check-circle" :heading="__('Todos los partidos pendientes de esta fecha ya tienen día asignado.')">
                        {{ __('Activa «Incluir partidos que ya tienen fecha» si quieres volver a programarlos.') }}
                    </flux:callout>
                @else
                    @if ($venues->isEmpty())
                        <flux:callout variant="warning" icon="map-pin" :heading="__('Aún no tienes canchas registradas.')">
                            <a href="{{ route('venues.create') }}" wire:navigate class="underline">{{ __('Registra una cancha') }}</a>
                            {{ __('para poder asignarla. También puedes programar solo el día y la hora.') }}
                        </flux:callout>
                    @endif

                    <form method="POST" action="{{ route('tournaments.programming.preview', $tournament) }}" class="space-y-6">
                        @csrf
                        <input type="hidden" name="round" value="{{ $round }}">
                        <input type="hidden" name="overwrite" value="{{ $overwrite ? 1 : 0 }}">

                        <div class="space-y-4">
                            @foreach ($rows as $row)
                                <div class="space-y-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:heading size="sm">{{ $row['category']->name }}</flux:heading>

                                        @if ($row['group'])
                                            <flux:badge size="sm" color="zinc">{{ $row['group'] }}</flux:badge>
                                        @endif

                                        <flux:badge size="sm" color="cyan">
                                            {{ trans_choice(':count partido|:count partidos', $row['matches']->count(), ['count' => $row['matches']->count()]) }}
                                        </flux:badge>
                                    </div>

                                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                        <flux:input
                                            name="rows[{{ $row['key'] }}][date]"
                                            type="date"
                                            label="{{ __('Día') }}"
                                            value="{{ old('rows.'.$row['key'].'.date') }}"
                                        />

                                        <flux:select
                                            name="rows[{{ $row['key'] }}][venue_id]"
                                            label="{{ __('Cancha') }}"
                                            placeholder="{{ __('Sin cancha') }}"
                                        >
                                            @foreach ($venues as $venue)
                                                <flux:select.option value="{{ $venue->id }}" :selected="(string) $venue->id === (string) old('rows.'.$row['key'].'.venue_id')">
                                                    {{ $venue->name }}
                                                </flux:select.option>
                                            @endforeach
                                        </flux:select>

                                        <flux:input
                                            name="rows[{{ $row['key'] }}][start]"
                                            type="time"
                                            label="{{ __('Primera hora (opcional)') }}"
                                            value="{{ old('rows.'.$row['key'].'.start') }}"
                                        />

                                        <flux:input
                                            name="rows[{{ $row['key'] }}][rest]"
                                            type="number"
                                            label="{{ __('Descanso entre partidos (min)') }}"
                                            value="{{ old('rows.'.$row['key'].'.rest') }}"
                                            min="0"
                                            max="120"
                                            placeholder="0"
                                        />
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <flux:text class="text-xs text-zinc-500 dark:text-white/50">
                            {{ __('Las categorías sin día se dejan como están. Si varias categorías comparten cancha y día, las horas se van encadenando en el orden de esta lista (la más pequeña primero). Antes de guardar verás una vista previa donde puedes ajustar cada partido.') }}
                        </flux:text>

                        <flux:button type="submit" variant="primary" icon="eye">{{ __('Ver vista previa') }}</flux:button>
                    </form>
                @endif
            @else
                <flux:text class="text-zinc-500 dark:text-white/60">{{ __('Elige una fecha para empezar.') }}</flux:text>
            @endif
        @endif
    </div>
</x-layouts::app>
