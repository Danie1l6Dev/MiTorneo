<x-layouts::app :title="$phase->name">
    @if (session('drawReveal'))
        @include('pages.phases._draw-reveal', [
            'phase' => $phase,
            'matches' => $phase->matches()->where('round_number', 1)->with(['homeTeam', 'awayTeam'])->orderBy('id')->get(),
        ])
    @endif

    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$phase->name">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Mis torneos'), 'href' => route('dashboard')],
                    ['label' => $phase->category->tournament->name, 'href' => route('tournaments.show', $phase->category->tournament)],
                    ['label' => $phase->category->name, 'href' => route('categories.show', $phase->category)],
                    ['label' => $phase->name],
                ]" />
            </x-slot:breadcrumbs>

            <div class="mt-1 flex items-center gap-2">
                <flux:badge size="sm" :color="$phase->type->color()">{{ $phase->type->label() }}</flux:badge>
            </div>

            <flux:text class="mt-2 text-sm text-zinc-500">
                {{ __('Los grupos de esta categoría se gestionan desde') }}
                <a href="{{ route('categories.show', $phase->category) }}" wire:navigate class="underline">{{ __('la página de la categoría') }}</a>.
            </flux:text>

            <x-slot:actions>
                <flux:button :href="route('phases.edit', $phase)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <form method="POST" action="{{ route('phases.destroy', $phase) }}" onsubmit="return confirm('{{ __('¿Eliminar esta fase? Se eliminarán también sus calendarios y partidos.') }}')">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-circle" :heading="$errors->first()" />
        @endif

        <flux:separator variant="subtle" />

        @if ($phase->type === \App\Enums\CompetitionPhaseType::League)
            <x-ui.section-tabs :tabs="[
                ['label' => __('Calendario'), 'href' => '#calendario', 'icon' => 'calendar-days'],
                ['label' => __('Tabla de posiciones'), 'href' => '#tabla-posiciones', 'icon' => 'table-cells'],
            ]" />

            <div
                id="calendario"
                class="scroll-mt-24 space-y-4"
                x-data="{
                    activeGroup: 0,
                    startRound: {{ \Illuminate\Support\Js::from($schedules->pluck('start_round_index')->values()) }},
                    currentRound: {{ \Illuminate\Support\Js::from($schedules->pluck('start_round_index')->values()) }},
                }"
            >
                <flux:heading size="lg">{{ __('Calendario') }}</flux:heading>

                @if ($schedules->isEmpty())
                    <x-ui.empty-state icon="calendar-days" :message="__('Todavía no se ha generado ningún calendario para esta fase.')" />
                @else
                    @if ($schedules->count() > 1)
                        <div class="inline-flex flex-wrap gap-1.5 rounded-xl border border-zinc-200 bg-zinc-100/70 p-1.5 dark:border-white/10 dark:bg-white/5">
                            @foreach ($schedules as $index => $item)
                                <button
                                    type="button"
                                    @click="activeGroup = {{ $index }}; currentRound[{{ $index }}] = startRound[{{ $index }}]"
                                    :class="activeGroup === {{ $index }} ? 'bg-white text-zinc-900 shadow-[0_0_0_1px_var(--color-accent)] dark:bg-white/10 dark:text-white' : 'text-zinc-600 hover:bg-white/60 dark:text-white/70 dark:hover:bg-white/10 dark:hover:text-white'"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium transition-all duration-150 hover:scale-105 active:scale-95"
                                >
                                    <flux:icon.squares-2x2 variant="micro" class="size-4 text-amber-400" />
                                    {{ $item['schedule']->group?->name ?? $category->name }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($schedules as $index => $item)
                        @php [$schedule, $rounds] = [$item['schedule'], $item['rounds']]; @endphp
                        @php $lastRoundIndex = max(count($rounds) - 1, 0); @endphp

                        <div
                            x-show="activeGroup === {{ $index }}"
                            @if ($schedules->count() > 1) x-cloak @endif
                            x-transition:enter="transition ease-out duration-300"
                            x-transition:enter-start="opacity-0 translate-y-2 scale-[0.99]"
                            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                            class="relative space-y-6 overflow-hidden rounded-3xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-7"
                        >
                            <div class="pointer-events-none absolute -top-32 left-1/2 h-56 w-[140%] -translate-x-1/2 bg-gradient-to-b from-green-500/15 via-cyan-500/5 to-transparent blur-2xl"></div>

                            <div class="relative flex flex-wrap items-center justify-between gap-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-amber-500/15 text-amber-400">
                                        <flux:icon.squares-2x2 variant="micro" class="size-5" />
                                    </div>
                                    <flux:heading size="sm" class="text-lg!">
                                        {{ $schedule->group?->name ?? $category->name }}
                                    </flux:heading>
                                </div>
                                <flux:badge size="sm" color="zinc">{{ __('FORMATO: :format', ['format' => mb_strtoupper($schedule->format->label())]) }}</flux:badge>
                            </div>

                            @if (count($rounds) === 0)
                                <x-ui.empty-state icon="calendar-days" :message="__('Esta tabla todavía no tiene partidos.')" />
                            @else
                                <div class="relative flex items-center justify-center gap-4 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="chevron-left"
                                        @click="currentRound[{{ $index }}] = Math.max(0, currentRound[{{ $index }}] - 1)"
                                        x-bind:disabled="currentRound[{{ $index }}] <= 0"
                                    />

                                    <div class="flex items-center gap-2">
                                        <flux:icon.calendar-days variant="micro" class="size-4 text-accent-content" />
                                        <flux:text class="w-28 text-center text-sm font-semibold text-zinc-700 dark:text-white/85" x-text="'{{ __('Jornada') }} ' + (currentRound[{{ $index }}] + 1) + ' {{ __('de') }} {{ count($rounds) }}'"></flux:text>
                                    </div>

                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="chevron-right"
                                        @click="currentRound[{{ $index }}] = Math.min({{ $lastRoundIndex }}, currentRound[{{ $index }}] + 1)"
                                        x-bind:disabled="currentRound[{{ $index }}] >= {{ $lastRoundIndex }}"
                                    />
                                </div>

                                @foreach ($rounds as $roundIdx => $round)
                                    <div
                                        x-show="currentRound[{{ $index }}] === {{ $roundIdx }}"
                                        x-cloak
                                        x-transition:enter="transition ease-out duration-250"
                                        x-transition:enter-start="opacity-0 translate-x-3"
                                        x-transition:enter-end="opacity-100 translate-x-0"
                                        class="relative"
                                    >
                                        <div class="mb-3 text-center text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                            {{ __('Jornada :number', ['number' => $round['round_number']]) }}
                                            @if ($schedule->format === \App\Enums\ScheduleFormat::HomeAndAway)
                                                — {{ $round['leg'] === 1 ? __('Primera vuelta') : __('Segunda vuelta') }}
                                            @endif
                                        </div>

                                        <div class="flex flex-wrap justify-center gap-4">
                                            @foreach ($round['matches'] as $match)
                                                <x-ui.match-card :match="$match" :href="route('matches.edit', $match)" />
                                            @endforeach

                                            @if ($round['resting_team'])
                                                <x-ui.match-card :resting="$round['resting_team']->name" />
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                @endif

                @if ($schedules->isEmpty())
                    <div class="space-y-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                        <flux:heading size="sm">{{ __('Generar calendario') }}</flux:heading>

                        <form method="POST" action="{{ route('phases.schedule.store', $phase) }}" class="space-y-4">
                            @csrf

                            <flux:radio.group name="format" label="{{ __('Formato de liga') }}">
                                <flux:radio
                                    value="{{ \App\Enums\ScheduleFormat::SingleRound->value }}"
                                    label="{{ \App\Enums\ScheduleFormat::SingleRound->label() }}"
                                    description="{{ \App\Enums\ScheduleFormat::SingleRound->description() }}"
                                    :checked="old('format', \App\Enums\ScheduleFormat::SingleRound->value) === \App\Enums\ScheduleFormat::SingleRound->value"
                                />
                                <flux:radio
                                    value="{{ \App\Enums\ScheduleFormat::HomeAndAway->value }}"
                                    label="{{ \App\Enums\ScheduleFormat::HomeAndAway->label() }}"
                                    description="{{ \App\Enums\ScheduleFormat::HomeAndAway->description() }}"
                                    :checked="old('format') === \App\Enums\ScheduleFormat::HomeAndAway->value"
                                />
                            </flux:radio.group>

                            <flux:button type="submit" variant="primary" size="sm">{{ __('Generar calendario') }}</flux:button>
                        </form>
                    </div>
                @else
                    <div class="flex items-center justify-between gap-4 rounded-2xl border border-red-500/20 p-5 dark:border-red-400/20 glass-panel">
                        <div class="space-y-1">
                            <flux:heading size="sm">{{ __('Eliminar calendario') }}</flux:heading>
                            <flux:text class="text-zinc-500 dark:text-white/60">
                                {{ __('Borra todas las jornadas y partidos generados para poder crear un calendario nuevo.') }}
                            </flux:text>
                        </div>

                        <form method="POST" action="{{ route('phases.schedule.destroy', $phase) }}" onsubmit="return confirm('{{ __('¿Eliminar el calendario generado? Se borrarán todas las jornadas y sus partidos. Esta acción no se puede deshacer.') }}')">
                            @csrf
                            @method('DELETE')
                            <flux:button type="submit" variant="danger" size="sm" icon="trash">{{ __('Eliminar calendario') }}</flux:button>
                        </form>
                    </div>
                @endif
            </div>

            <flux:separator variant="subtle" />

            <div id="tabla-posiciones" class="scroll-mt-24 space-y-4">
                <flux:heading size="lg">{{ __('Tabla de posiciones') }}</flux:heading>

                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($standings as $item)
                        <x-ui.standings-table :label="$item['label']" :rows="$item['rows']" />
                    @endforeach
                </div>
            </div>

            @if ($readyToAdvance)
                <div class="space-y-3 rounded-2xl border border-green-500/25 p-5 dark:border-green-400/25 glass-panel">
                    <flux:heading size="sm">{{ __('¿Pasar a la siguiente fase?') }}</flux:heading>
                    <flux:text>
                        {{ __('Todos los partidos de esta fase han finalizado. Puedes definir cuántos equipos clasifican y crear la siguiente fase mediante un sorteo en vivo o una nueva fase de liga.') }}
                    </flux:text>
                    <flux:button :href="route('phases.advance.create', $phase)" variant="primary" size="sm" wire:navigate>
                        {{ __('Definir clasificados') }}
                    </flux:button>
                </div>
            @endif

            <flux:separator variant="subtle" />
        @else
            <div id="cuadro" class="scroll-mt-24 space-y-6">
                <flux:heading size="lg">{{ __('Cuadro de eliminación') }}</flux:heading>

                @if (empty($bracketRounds))
                    <x-ui.empty-state icon="bolt" :message="__('Todavía no se ha generado el cuadro de esta fase.')" />
                @else
                    {{-- Mobile/tablet: rounds stacked top to bottom, one under the other. --}}
                    <div class="space-y-6 lg:hidden">
                        @foreach ($bracketRounds as $round)
                            <div class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400">
                                        <flux:icon.bolt variant="micro" class="size-4" />
                                    </div>
                                    <flux:heading size="sm" class="text-base!">{{ $round['label'] }}</flux:heading>
                                </div>

                                <div class="flex flex-wrap justify-center gap-4">
                                    @foreach ($round['matches'] as $match)
                                        <x-ui.match-card :match="$match" :href="route('matches.edit', $match)" />
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{--
                        Desktop: the classic bracket shape -- rounds converge from a left and a
                        right column toward the final in the middle. Every non-final column is
                        chunked into pairs of 2 matches (always possible: rounds are always a
                        power of 2); a pair gets a vertical bar joining both cards' midpoints
                        plus a stub reaching all the way into the next column, while a lone
                        match (a column with only 1 match, when it feeds the final directly)
                        just gets a straight stub. The receiving column never needs its own
                        connector: the sending side's line already reaches its edge.

                        `justify-around` on every column, combined with `items-stretch` making
                        every column share round 1's full height, is what keeps a pair's bar
                        exactly centered on its two matches and exactly aligned with what it
                        feeds in the next column, at any bracket depth -- no pixel math needed.
                    --}}
                    <div class="hidden overflow-x-auto pb-4 lg:block">
                        <div class="mx-auto flex w-max items-stretch gap-14 px-2">
                            @foreach ($bracketColumns as $column)
                                <div class="flex w-64 shrink-0 flex-col">
                                    {{-- The label sits outside the matches' own flex container below: if it
                                         shared that container's justify-around, it would count as one more
                                         item in the space-around split, throwing every column's math off by
                                         a different amount depending on how tall that column's own match
                                         content is -- which is exactly what caused the previous misalignment. --}}
                                    <div class="mb-3 flex items-center justify-center gap-1.5 text-center text-xs font-semibold uppercase tracking-wider
                                        {{ $column['side'] === 'final' ? 'text-amber-400' : 'text-zinc-500 dark:text-white/50' }}">
                                        @if ($column['side'] === 'final')
                                            <flux:icon.trophy variant="micro" class="size-3.5" />
                                        @endif
                                        {{ $column['label'] }}
                                    </div>

                                    <div class="flex flex-1 flex-col {{ $column['side'] === 'final' ? 'justify-center' : 'justify-around' }}">
                                        @if ($column['side'] === 'final')
                                            <x-ui.bracket-match-card :match="$column['matches']->first()" :href="route('matches.edit', $column['matches']->first())" />
                                        @else
                                            @foreach ($column['matches']->chunk(2) as $pair)
                                                @if ($pair->count() === 2)
                                                    <div @class([
                                                        'relative flex flex-col justify-between gap-4',
                                                        "after:content-[''] after:absolute after:top-8 after:bottom-8 after:w-0.5 after:bg-zinc-400 dark:after:bg-white/35" => true,
                                                        "before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:bg-zinc-400 dark:before:bg-white/35" => true,
                                                        'after:-right-7 before:-right-14 before:w-7' => $column['side'] === 'left',
                                                        'after:-left-7 before:-left-14 before:w-7' => $column['side'] === 'right',
                                                    ])>
                                                        {{-- Each card gets its own short stub reaching the shared vertical
                                                             bar above; it must live on this plain wrapper (not the card
                                                             itself) since the card's own overflow-hidden would clip it. --}}
                                                        @foreach ($pair as $match)
                                                            <div @class([
                                                                'relative',
                                                                "after:content-[''] after:absolute after:top-1/2 after:h-0.5 after:w-7 after:bg-zinc-400 dark:after:bg-white/35 after:-right-7" => $column['side'] === 'left',
                                                                "before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:w-7 before:bg-zinc-400 dark:before:bg-white/35 before:-left-7" => $column['side'] === 'right',
                                                            ])>
                                                                <x-ui.bracket-match-card :match="$match" :href="route('matches.edit', $match)" />
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <div @class([
                                                        'relative',
                                                        "after:content-[''] after:absolute after:top-1/2 after:h-0.5 after:w-14 after:bg-zinc-400 dark:after:bg-white/35 after:-right-14" => $column['side'] === 'left',
                                                        "before:content-[''] before:absolute before:top-1/2 before:h-0.5 before:w-14 before:bg-zinc-400 dark:before:bg-white/35 before:-left-14" => $column['side'] === 'right',
                                                    ])>
                                                        <x-ui.bracket-match-card :match="$pair->first()" :href="route('matches.edit', $pair->first())" />
                                                    </div>
                                                @endif
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if ($champion)
                        <div class="relative mx-auto flex max-w-sm flex-col items-center gap-3 overflow-hidden rounded-3xl border border-amber-500/40 p-8 text-center glass-panel-strong dark:border-amber-400/30">
                            <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-amber-500/20 via-transparent to-transparent"></div>

                            <div class="relative flex size-16 items-center justify-center rounded-full bg-amber-500/20 text-amber-400">
                                <flux:icon.trophy variant="outline" class="size-8" />
                            </div>
                            <div class="relative text-xs font-semibold uppercase tracking-widest text-amber-500 dark:text-amber-400">{{ __('Campeón') }}</div>
                            <flux:heading size="xl" class="relative">{{ $champion->name }}</flux:heading>
                        </div>
                    @endif
                @endif
            </div>

            <flux:separator variant="subtle" />
        @endif

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Partidos sin calendario asignado') }}</flux:heading>

                <flux:button :href="route('phases.matches.create', $phase)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Nuevo partido') }}
                </flux:button>
            </div>

            @if ($unscheduledMatches->isEmpty())
                <x-ui.empty-state icon="flag" :message="__('No hay partidos cargados manualmente en esta fase.')" />
            @else
                <div class="flex flex-wrap justify-center gap-4">
                    @foreach ($unscheduledMatches as $match)
                        <x-ui.match-card :match="$match" :href="route('matches.edit', $match)" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
