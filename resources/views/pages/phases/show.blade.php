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

            <div id="calendario" class="scroll-mt-24 space-y-4">
                <flux:heading size="lg">{{ __('Calendario') }}</flux:heading>

                @forelse ($schedules as $item)
                    @php [$schedule, $rounds] = [$item['schedule'], $item['rounds']]; @endphp

                    <div class="space-y-5 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <flux:heading size="sm">
                                {{ $schedule->group?->name ?? $category->name }}
                            </flux:heading>
                            <flux:badge size="sm" color="zinc">{{ __('FORMATO: :format', ['format' => mb_strtoupper($schedule->format->label())]) }}</flux:badge>
                        </div>

                        <div class="space-y-5">
                            @foreach ($rounds as $round)
                                <div>
                                    <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                        {{ __('Jornada :number', ['number' => $round['round_number']]) }}
                                        @if ($schedule->format === \App\Enums\ScheduleFormat::HomeAndAway)
                                            — {{ $round['leg'] === 1 ? __('Primera vuelta') : __('Segunda vuelta') }}
                                        @endif
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach ($round['matches'] as $match)
                                            <x-ui.match-card :match="$match" :href="route('matches.edit', $match)" />
                                        @endforeach

                                        @if ($round['resting_team'])
                                            <x-ui.match-card :resting="$round['resting_team']->name" />
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="calendar-days" :message="__('Todavía no se ha generado ningún calendario para esta fase.')" />
                @endforelse

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
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($unscheduledMatches as $match)
                        <x-ui.match-card :match="$match" :href="route('matches.edit', $match)" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
