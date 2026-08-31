<x-layouts::app :title="$phase->name">
    <div class="w-full space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:button :href="route('categories.show', $phase->category)" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
                    {{ $phase->category->name }}
                </flux:button>

                <flux:heading size="xl" class="mt-2">{{ $phase->name }}</flux:heading>
                <flux:badge size="sm" class="mt-1">{{ $phase->type->label() }}</flux:badge>
                <flux:text class="mt-2 text-sm text-zinc-500">
                    {{ __('Los grupos de esta categoría se gestionan desde') }}
                    <a href="{{ route('categories.show', $phase->category) }}" wire:navigate class="underline">{{ __('la página de la categoría') }}</a>.
                </flux:text>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <flux:button :href="route('phases.edit', $phase)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <form method="POST" action="{{ route('phases.destroy', $phase) }}" onsubmit="return confirm('{{ __('¿Eliminar esta fase? Se eliminarán también sus calendarios y partidos.') }}')">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-circle" :heading="$errors->first()" />
        @endif

        <flux:separator />

        @if ($phase->type === \App\Enums\CompetitionPhaseType::League)
            <div class="flex gap-2">
                <flux:button href="#calendario" variant="ghost" size="sm">{{ __('Calendario') }}</flux:button>
                <flux:button href="#tabla-posiciones" variant="ghost" size="sm">{{ __('Tabla de posiciones') }}</flux:button>
            </div>

            <div id="calendario" class="space-y-4">
                <flux:heading size="lg">{{ __('Calendario') }}</flux:heading>

                @forelse ($schedules as $item)
                    @php [$schedule, $rounds] = [$item['schedule'], $item['rounds']]; @endphp

                    <flux:card class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <flux:heading size="sm">
                                {{ $schedule->group?->name ?? $category->name }}
                            </flux:heading>
                            <flux:badge size="sm" color="zinc">{{ __('FORMATO: :format', ['format' => mb_strtoupper($schedule->format->label())]) }}</flux:badge>
                        </div>

                        <div class="space-y-4">
                            @foreach ($rounds as $round)
                                <div>
                                    <flux:text class="font-medium">
                                        {{ __('Jornada :number', ['number' => $round['round_number']]) }}
                                        @if ($schedule->format === \App\Enums\ScheduleFormat::HomeAndAway)
                                            — {{ $round['leg'] === 1 ? __('Primera vuelta') : __('Segunda vuelta') }}
                                        @endif
                                    </flux:text>

                                    <div class="mt-1 space-y-1">
                                        @foreach ($round['matches'] as $match)
                                            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                                                <a href="{{ route('matches.edit', $match) }}" wire:navigate class="hover:underline">
                                                    {{ $match->homeTeam->name }}
                                                    @if ($match->status === \App\Enums\MatchStatus::Finished)
                                                        {{ $match->home_score }} - {{ $match->away_score }}
                                                    @else
                                                        vs
                                                    @endif
                                                    {{ $match->awayTeam->name }}
                                                </a>
                                            </flux:text>
                                        @endforeach

                                        @if ($round['resting_team'])
                                            <flux:text class="text-sm text-zinc-400 italic">
                                                {{ $round['resting_team']->name }} — {{ __('DESCANSA') }}
                                            </flux:text>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @empty
                    <flux:text class="text-zinc-500">{{ __('Todavía no se ha generado ningún calendario para esta fase.') }}</flux:text>
                @endforelse

                <flux:card class="space-y-4">
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
                </flux:card>
            </div>

            <flux:separator />

            <div id="tabla-posiciones" class="space-y-4">
                <flux:heading size="lg">{{ __('Tabla de posiciones') }}</flux:heading>

                @foreach ($standings as $item)
                    <flux:card class="space-y-3">
                        <flux:heading size="sm">{{ $item['label'] }}</flux:heading>

                        @if (count($item['rows']) === 0)
                            <flux:text class="text-zinc-500">{{ __('Todavía no hay equipos para esta tabla.') }}</flux:text>
                        @else
                            <div class="overflow-x-auto">
                                <flux:table>
                                    <flux:table.columns>
                                        <flux:table.column>{{ __('Pos') }}</flux:table.column>
                                        <flux:table.column>{{ __('Equipo') }}</flux:table.column>
                                        <flux:table.column>{{ __('PJ') }}</flux:table.column>
                                        <flux:table.column>{{ __('PG') }}</flux:table.column>
                                        <flux:table.column>{{ __('PE') }}</flux:table.column>
                                        <flux:table.column>{{ __('PP') }}</flux:table.column>
                                        <flux:table.column>{{ __('GF') }}</flux:table.column>
                                        <flux:table.column>{{ __('GC') }}</flux:table.column>
                                        <flux:table.column>{{ __('DG') }}</flux:table.column>
                                        <flux:table.column>{{ __('PTS') }}</flux:table.column>
                                    </flux:table.columns>

                                    <flux:table.rows>
                                        @foreach ($item['rows'] as $index => $row)
                                            <flux:table.row>
                                                <flux:table.cell>{{ $index + 1 }}</flux:table.cell>
                                                <flux:table.cell>{{ $row['team']->name }}</flux:table.cell>
                                                <flux:table.cell>{{ $row['played'] }}</flux:table.cell>
                                                <flux:table.cell>{{ $row['won'] }}</flux:table.cell>
                                                <flux:table.cell>{{ $row['drawn'] }}</flux:table.cell>
                                                <flux:table.cell>{{ $row['lost'] }}</flux:table.cell>
                                                <flux:table.cell>{{ $row['goals_for'] }}</flux:table.cell>
                                                <flux:table.cell>{{ $row['goals_against'] }}</flux:table.cell>
                                                <flux:table.cell>{{ $row['goal_difference'] }}</flux:table.cell>
                                                <flux:table.cell class="font-semibold">{{ $row['points'] }}</flux:table.cell>
                                            </flux:table.row>
                                        @endforeach
                                    </flux:table.rows>
                                </flux:table>
                            </div>
                        @endif
                    </flux:card>
                @endforeach
            </div>

            <flux:separator />
        @endif

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Partidos sin calendario asignado') }}</flux:heading>

                <flux:button :href="route('phases.matches.create', $phase)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Nuevo partido') }}
                </flux:button>
            </div>

            @if ($unscheduledMatches->isEmpty())
                <flux:text class="text-zinc-500">{{ __('No hay partidos cargados manualmente en esta fase.') }}</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Local') }}</flux:table.column>
                        <flux:table.column>{{ __('Visitante') }}</flux:table.column>
                        <flux:table.column>{{ __('Resultado') }}</flux:table.column>
                        <flux:table.column>{{ __('Estado') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($unscheduledMatches as $match)
                            <flux:table.row>
                                <flux:table.cell>{{ $match->homeTeam->name }}</flux:table.cell>
                                <flux:table.cell>{{ $match->awayTeam->name }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $match->home_score ?? '-' }} : {{ $match->away_score ?? '-' }}
                                </flux:table.cell>
                                <flux:table.cell>{{ $match->status->label() }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:button :href="route('matches.edit', $match)" variant="ghost" size="sm" icon="pencil" wire:navigate />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    </div>
</x-layouts::app>
