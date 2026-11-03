<x-layouts::app :title="__('Pasar a la siguiente fase')">
    <div class="mx-auto w-full max-w-xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Pasar a la siguiente fase')" :subtitle="$phase->category->name">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => $phase->name, 'href' => route('phases.show', $phase)],
                    ['label' => __('Pasar a la siguiente fase')],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        @if (session('error') || $errors->any())
            <flux:callout variant="danger" icon="exclamation-circle" :heading="$errors->first() ?? session('error')" />
        @endif

        <div class="space-y-3 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
            <flux:heading size="sm">{{ __('Resultado de la ronda de cruces') }}</flux:heading>

            @if ($outcome['direct']->isNotEmpty())
                <div class="space-y-1">
                    <flux:text class="text-sm font-medium">{{ __('Pasaron directo (:count)', ['count' => $outcome['direct']->count()]) }}</flux:text>
                    <flux:text class="text-sm">{{ $outcome['direct']->pluck('name')->join(', ') }}</flux:text>
                </div>
            @endif

            <div class="space-y-1">
                <flux:text class="text-sm font-medium">{{ __('Ganadores (:count)', ['count' => $outcome['winners']->count()]) }}</flux:text>
                <flux:text class="text-sm">{{ $outcome['winners']->pluck('name')->join(', ') }}</flux:text>
            </div>

            @if ($outcome['losers'] !== [])
                <div class="space-y-1">
                    <flux:text class="text-sm font-medium">{{ __('Perdedores (:count)', ['count' => count($outcome['losers'])]) }}</flux:text>
                    <flux:text class="text-sm">{{ collect($outcome['losers'])->map(fn ($loser) => $loser['team']->name)->join(', ') }}</flux:text>
                </div>
            @endif
        </div>

        @php
            $hasPendingTies = collect($outcome['losers'])->contains(fn (array $loser): bool => ($loser['tie'] ?? null) === 'pending');
        @endphp

        @if ($hasPendingTies)
            {{-- Ties among the losers are settled on the phase page (with its
                 sorteo buttons), not here: the form stays locked until then. --}}
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Primero resuelve los desempates')">
                <flux:callout.text>
                    {{ __('Hay equipos empatados en todos los criterios entre los mejores perdedores. Realiza los sorteos en la fase y luego vuelve a definir los clasificados.') }}
                </flux:callout.text>
                <x-slot:actions>
                    <flux:button :href="route('phases.show', $phase)" variant="primary" size="sm" icon:trailing="chevron-right" wire:navigate>
                        {{ __('Ir a :name', ['name' => $phase->name]) }}
                    </flux:button>
                </x-slot:actions>
            </flux:callout>
        @else
        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form
                method="POST"
                action="{{ route('phases.advance-cross.store', $phase) }}"
                class="space-y-6"
                x-data="{ type: '{{ old('type', \App\Enums\CompetitionPhaseType::Knockout->value) }}' }"
            >
                @csrf

                <flux:input
                    name="name"
                    label="{{ __('Nombre de la nueva fase') }}"
                    value="{{ old('name') }}"
                    placeholder="{{ __('Semifinales') }}"
                    required
                    autofocus
                />

                <div class="space-y-1.5">
                    <flux:input
                        name="best_losers"
                        type="number"
                        min="0"
                        max="{{ count($outcome['losers']) }}"
                        label="{{ __('¿Cuántos de los mejores perdedores clasifican?') }}"
                        value="{{ old('best_losers', 0) }}"
                        required
                    />
                    <flux:text class="text-xs text-zinc-500">
                        {{ __('Total sin mejores perdedores: :count equipos. Si hay empate entre perdedores, el orden mostrado arriba es el que se aplica (JL son los puntos de juego limpio: menos es mejor).', ['count' => $outcome['direct']->count() + $outcome['winners']->count()]) }}
                    </flux:text>
                </div>

                <flux:select name="type" label="{{ __('Tipo de la nueva fase') }}" x-model="type">
                    @php $currentType = old('type', \App\Enums\CompetitionPhaseType::Knockout->value); @endphp

                    <flux:select.option value="{{ \App\Enums\CompetitionPhaseType::Knockout->value }}" :selected="$currentType === \App\Enums\CompetitionPhaseType::Knockout->value">
                        {{ __('Eliminación directa (cuadro completo)') }}
                    </flux:select.option>
                    <flux:select.option value="{{ \App\Enums\CompetitionPhaseType::Semifinal->value }}" :selected="$currentType === \App\Enums\CompetitionPhaseType::Semifinal->value">
                        {{ __('Semifinales (4 equipos)') }}
                    </flux:select.option>
                    <flux:select.option value="{{ \App\Enums\CompetitionPhaseType::Final->value }}" :selected="$currentType === \App\Enums\CompetitionPhaseType::Final->value">
                        {{ __('Final (2 equipos)') }}
                    </flux:select.option>
                    <flux:select.option value="cross_round" :selected="$currentType === 'cross_round'">
                        {{ __('Otra ronda de cruces (cantidad par)') }}
                    </flux:select.option>
                </flux:select>

                <flux:radio.group name="draw_method" label="{{ __('¿Cómo se sortean los cruces?') }}">
                    @foreach (\App\Enums\DrawMethod::cases() as $method)
                        <flux:radio
                            value="{{ $method->value }}"
                            label="{{ __($method->label()) }}"
                            description="{{ $method === \App\Enums\DrawMethod::Random ? __('Los cruces se sortean al azar.') : __('El mejor clasificado (según su puesto en la tabla de su grupo) cruza con el peor, el segundo mejor con el segundo peor, y así sucesivamente.') }}"
                            :checked="old('draw_method', \App\Enums\DrawMethod::Random->value) === $method->value"
                        />
                    @endforeach
                </flux:radio.group>

                @if ($groupCount > 1)
                    <flux:radio.group name="pairing_scope" label="{{ __('¿Quién puede enfrentarse a quién?') }}">
                        @foreach (\App\Enums\PairingScope::cases() as $scope)
                            <flux:radio
                                value="{{ $scope->value }}"
                                label="{{ __($scope->label()) }}"
                                description="{{ __($scope->description()) }}"
                                :checked="old('pairing_scope', \App\Enums\PairingScope::Anyone->value) === $scope->value"
                            />
                        @endforeach
                    </flux:radio.group>
                @else
                    <input type="hidden" name="pairing_scope" value="{{ \App\Enums\PairingScope::Anyone->value }}">
                @endif

                {{-- Removed from the DOM (not just hidden) for a ronda de
                     cruces: it is always played as single matches, and the
                     server expects none of these fields then. --}}
                <template x-if="type !== 'cross_round'">
                    <div class="space-y-6">
                        <div class="space-y-1.5">
                            <flux:radio.group name="knockout_format" label="{{ __('Formato de los cruces') }}">
                                @foreach (\App\Enums\ScheduleFormat::cases() as $format)
                                    <flux:radio
                                        value="{{ $format->value }}"
                                        label="{{ $format === \App\Enums\ScheduleFormat::HomeAndAway ? __('Ida y vuelta') : __('Partido único') }}"
                                        description="{{ $format === \App\Enums\ScheduleFormat::HomeAndAway ? __('Cada cruce se juega en dos partidos; el resultado global decide quién avanza.') : __('Un solo partido decide el cruce.') }}"
                                        :checked="old('knockout_format', \App\Enums\ScheduleFormat::SingleRound->value) === $format->value"
                                    />
                                @endforeach
                            </flux:radio.group>
                        </div>

                        <div x-show="['{{ \App\Enums\CompetitionPhaseType::Knockout->value }}', '{{ \App\Enums\CompetitionPhaseType::Semifinal->value }}'].includes(type)" x-cloak>
                            <flux:checkbox
                                name="plays_third_place"
                                value="1"
                                label="{{ __('Jugar partido por el 3er y 4to puesto') }}"
                                description="{{ __('Los dos equipos eliminados en semifinales juegan un partido único aparte, sin importar el formato del resto del cuadro.') }}"
                                :checked="old('plays_third_place')"
                            />
                        </div>

                        <div x-data="{ finalDiffers: {{ old('final_knockout_format') ? 'true' : 'false' }} }" class="space-y-1.5">
                            <flux:checkbox
                                label="{{ __('La final tiene un formato distinto al resto del cuadro') }}"
                                x-model="finalDiffers"
                            />

                            <template x-if="finalDiffers">
                                <flux:radio.group name="final_knockout_format" label="{{ __('Formato de la final') }}">
                                    @foreach (\App\Enums\ScheduleFormat::cases() as $format)
                                        <flux:radio
                                            value="{{ $format->value }}"
                                            label="{{ $format === \App\Enums\ScheduleFormat::HomeAndAway ? __('Ida y vuelta') : __('Partido único') }}"
                                            :checked="old('final_knockout_format') === $format->value"
                                        />
                                    @endforeach
                                </flux:radio.group>
                            </template>
                        </div>
                    </div>
                </template>

                <flux:button type="submit" variant="primary">{{ __('Continuar') }}</flux:button>
            </form>
        </div>
        @endif
    </div>
</x-layouts::app>
