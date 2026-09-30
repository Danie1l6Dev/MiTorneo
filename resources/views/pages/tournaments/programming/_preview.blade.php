{{--
    The proposal panel of "Programar jornada": every match of the chosen category
    in play order, each with the day / time / cancha the fields above produce
    (still editable one by one) and whatever clashes that slot has. Rendered
    with the page and re-rendered on its own by
    TournamentProgrammingController::preview(), swapped in as a raw HTML
    fragment (this.$refs.preview.innerHTML = ...) -- Alpine's own mutation
    observer picks up any x-data in it same as on first render, so
    x-ui.searchable-select (below) works here unchanged.
--}}
@php
    $control = 'w-full rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-800 shadow-xs focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500/30 dark:border-white/10 dark:bg-white/5 dark:text-white disabled:cursor-not-allowed disabled:bg-zinc-100 disabled:text-zinc-400 dark:disabled:bg-white/5 dark:disabled:text-white/30';
    $blocked = ! $preview['hasProposal'] || $preview['conflictCount'] > 0;
@endphp

<div class="space-y-4">
    @if (! $preview['hasProposal'])
        <flux:callout variant="secondary" icon="information-circle" :heading="__('Elige el día para ver la propuesta de horarios.')">
            {{ __('Estos son los partidos de la categoría. Al llenar los campos de arriba, cada uno recibe su día, hora y cancha; luego puedes ajustarlos uno por uno.') }}
        </flux:callout>
    @elseif ($preview['conflictCount'] > 0)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="trans_choice('Hay :count partido con cruces: corrígelo para poder guardar.|Hay :count partidos con cruces: corrígelos para poder guardar.', $preview['conflictCount'], ['count' => $preview['conflictCount']])" />
    @else
        <flux:callout variant="success" icon="check-circle" :heading="__('Sin cruces. Revisa los horarios y guarda cuando estés conforme.')" />
    @endif

    @if ($preview['freeFrom'])
        {{-- The chosen cancha already has matches that day (say, another category's):
             offer to start where they end. useStart() lives on the page's Alpine root. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
            <span>{{ __('Esa cancha ya tiene partidos ese día: queda libre desde las :time.', ['time' => $preview['freeFrom']['label']]) }}</span>

            <flux:button type="button" size="sm" variant="ghost" icon="clock" x-on:click="useStart('{{ $preview['freeFrom']['time'] }}')">
                {{ __('Empezar a las :time', ['time' => $preview['freeFrom']['label']]) }}
            </flux:button>
        </div>
    @endif

    <div class="divide-y divide-zinc-100 rounded-2xl border border-zinc-200 px-5 dark:divide-white/5 dark:border-white/10 glass-panel">
        @foreach ($preview['items'] as $item)
            @php $match = $item['match']; @endphp

            {{-- A match marked "No programar" (a team can't play) is tinted amber so it
                 reads at a glance as "left out"; the rest of the rows stay fully editable. --}}
            <div class="space-y-2 py-3 @if ($item['skip']) -mx-3 my-1 rounded-xl border border-l-4 border-amber-500 bg-amber-500/10 px-3 @endif">
                <div class="flex flex-wrap items-center gap-2 text-sm font-medium text-zinc-800 dark:text-white">
                    <span>{{ $match->homeTeam->name }}</span>
                    <span class="text-zinc-400 dark:text-white/40">vs</span>
                    <span>{{ $match->awayTeam->name }}</span>

                    @if ($item['group'])
                        <flux:badge size="sm" color="zinc">{{ $item['group'] }}</flux:badge>
                    @endif

                    @if ($item['skip'])
                        <flux:badge size="sm" color="amber" icon="no-symbol">{{ __('No se programará') }}</flux:badge>
                    @endif
                </div>

                @if ($match->scheduleSummary())
                    <div class="text-xs text-zinc-500 dark:text-white/50">
                        {{ __('Actualmente') }}: {{ $match->scheduleSummary() }}
                    </div>
                @endif

                {{-- A ticked "no programar" match takes no slot (its team can't play):
                     its fields come back empty and locked (disabled inputs aren't posted, so the
                     server never sees them) while the rest is re-planned. --}}
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <input
                        type="date"
                        name="matches[{{ $match->id }}][date]"
                        value="{{ $item['date'] }}"
                        @disabled($item['skip'])
                        aria-label="{{ __('Día') }}"
                        class="{{ $control }}"
                    >

                    <input
                        type="time"
                        name="matches[{{ $match->id }}][time]"
                        value="{{ $item['time'] }}"
                        @disabled($item['skip'])
                        aria-label="{{ __('Hora') }}"
                        class="{{ $control }}"
                    >

                    <x-ui.searchable-select
                        name="matches[{{ $match->id }}][venue_id]"
                        :options="$venues->map(fn ($venue) => ['id' => $venue->id, 'label' => $venue->name])"
                        :selected="$item['skip'] ? null : ($item['venue_id'] ?: null)"
                        :disabled="$item['skip']"
                        :placeholder="__('Sin cancha')"
                        :search-placeholder="__('Buscar cancha...')"
                        :empty-message="__('Ninguna cancha coincide con la búsqueda.')"
                    />

                    <x-ui.searchable-select
                        name="matches[{{ $match->id }}][referee_id]"
                        :options="$referees->map(fn ($referee) => ['id' => $referee->id, 'label' => $referee->full_name])"
                        :selected="$item['skip'] ? null : ($item['referee_id'] ?: null)"
                        :disabled="$item['skip']"
                        :placeholder="__('Sin árbitro')"
                        :search-placeholder="__('Buscar árbitro...')"
                        :empty-message="__('Ningún árbitro coincide con la búsqueda.')"
                    />
                </div>

                {{-- Pill toggle: the checkbox stays the real (hidden) control; has-checked
                     gives instant feedback before the panel re-renders. --}}
                <div class="flex flex-wrap items-center gap-3">
                    <label class="inline-flex cursor-pointer select-none items-center gap-1.5 rounded-full border border-zinc-200 px-3 py-1 text-xs font-semibold text-zinc-500 transition hover:border-amber-400 hover:text-amber-600 has-checked:border-amber-500 has-checked:bg-amber-500/15 has-checked:text-amber-700 dark:border-white/15 dark:text-white/50 dark:hover:text-amber-300 dark:has-checked:text-amber-300">
                        <input
                            type="checkbox"
                            name="matches[{{ $match->id }}][skip]"
                            value="1"
                            @checked($item['skip'])
                            class="sr-only"
                            x-on:change.stop="clearTimeout(timer); refresh()"
                        >
                        <flux:icon.no-symbol variant="micro" class="size-3.5" />
                        <span>{{ $item['skip'] ? __('No se programará · deshacer') : __('No programar') }}</span>
                    </label>

                    @if ($item['skip'])
                        <span class="text-xs text-amber-700 dark:text-amber-300">{{ __('Un equipo no tiene disponibilidad: el partido sigue pendiente.') }}</span>
                    @endif
                </div>

                @foreach ($item['conflicts'] as $conflict)
                    <div class="flex items-start gap-1.5 text-xs text-red-600 dark:text-red-400">
                        <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                        <span>{{ $conflict }}</span>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <flux:text class="text-xs text-zinc-500 dark:text-white/50">
        {{ __('Todos los campos son opcionales: sin día, el partido conserva el suyo y solo recibe la cancha o el árbitro que elijas; una hora vacía significa «hora por definir». Un partido marcado «No programar» no se modifica.') }}
    </flux:text>

    <flux:button type="submit" variant="primary" icon="check" :disabled="$blocked">
        {{ __('Guardar programación') }}
    </flux:button>
</div>
