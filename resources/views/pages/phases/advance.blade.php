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

        <div class="space-y-2 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
            <flux:heading size="sm">{{ __('Tablas actuales') }}</flux:heading>

            @foreach ($tables as $table)
                <flux:text class="text-sm">
                    {{ $table['label'] }}: {{ count($table['rows']) }} {{ __('equipos') }}
                </flux:text>
            @endforeach
        </div>

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form
                method="POST"
                action="{{ route('phases.advance.store', $phase) }}"
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
                    <flux:select name="type" label="{{ __('Tipo de fase') }}" x-model="type">
                        @php $currentType = old('type', \App\Enums\CompetitionPhaseType::Knockout->value); @endphp

                        @foreach ($typeOptions as $option)
                            <flux:select.option
                                value="{{ $option['type']->value }}"
                                :selected="$option['type']->value === $currentType"
                                :disabled="! $option['available']"
                            >
                                {{ $option['type']->label() }}@if (! $option['available']) — {{ __('no disponible') }}@endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    @foreach (collect($typeOptions)->where('available', false) as $option)
                        <flux:text class="text-xs text-zinc-500">{{ $option['reason'] }}</flux:text>
                    @endforeach
                </div>

                <div
                    x-show="!['{{ \App\Enums\CompetitionPhaseType::Semifinal->value }}', '{{ \App\Enums\CompetitionPhaseType::Final->value }}'].includes(type)"
                    x-cloak
                    class="space-y-1.5"
                >
                    <flux:input
                        name="qualifiers_per_table"
                        type="number"
                        min="1"
                        max="{{ $maxPerTable ?: null }}"
                        label="{{ count($tables) > 1 ? __('¿Cuántos equipos clasifican de cada grupo?') : __('¿Cuántos equipos clasifican de la tabla?') }}"
                        value="{{ old('qualifiers_per_table', min(2, max($maxPerTable, 1))) }}"
                        x-bind:disabled="['{{ \App\Enums\CompetitionPhaseType::Semifinal->value }}', '{{ \App\Enums\CompetitionPhaseType::Final->value }}'].includes(type)"
                    />

                    @if ($maxPerTable > 0)
                        <flux:text class="text-xs text-zinc-500">
                            {{ __('Como máximo :count, según la tabla con menos equipos.', ['count' => $maxPerTable]) }}
                        </flux:text>
                    @endif
                </div>

                <div x-show="type === '{{ \App\Enums\CompetitionPhaseType::Semifinal->value }}'" x-cloak>
                    @if ($fixedPerTable[\App\Enums\CompetitionPhaseType::Semifinal->value] === null)
                        <flux:callout variant="danger" icon="exclamation-circle" :heading="__('No se pueden repartir 4 clasificados en partes iguales entre las tablas actuales.')" />
                    @else
                        <flux:callout variant="secondary" icon="information-circle" :heading="trans_choice('Clasifica :count equipo de cada tabla, automáticamente (4 en total).|Clasifican :count equipos de cada tabla, automáticamente (4 en total).', $fixedPerTable[\App\Enums\CompetitionPhaseType::Semifinal->value], ['count' => $fixedPerTable[\App\Enums\CompetitionPhaseType::Semifinal->value]])" />
                    @endif
                </div>

                <div x-show="type === '{{ \App\Enums\CompetitionPhaseType::Final->value }}'" x-cloak>
                    @if ($fixedPerTable[\App\Enums\CompetitionPhaseType::Final->value] === null)
                        <flux:callout variant="danger" icon="exclamation-circle" :heading="__('No se pueden repartir 2 clasificados en partes iguales entre las tablas actuales.')" />
                    @else
                        <flux:callout variant="secondary" icon="information-circle" :heading="trans_choice('Clasifica :count equipo de cada tabla, automáticamente (2 en total).|Clasifican :count equipos de cada tabla, automáticamente (2 en total).', $fixedPerTable[\App\Enums\CompetitionPhaseType::Final->value], ['count' => $fixedPerTable[\App\Enums\CompetitionPhaseType::Final->value]])" />
                    @endif
                </div>

                <template x-if="type !== '{{ \App\Enums\CompetitionPhaseType::League->value }}'">
                    <div class="space-y-1.5">
                        {{--
                            A league phase submits no draw_method at all (the
                            server rejects it if present), so this has to be
                            removed from the DOM -- not just hidden -- when
                            "Liga" is picked: Flux's radio is a form-associated
                            custom element, and x-show + x-bind:disabled left
                            its hidden internal input still submitting its
                            last-checked value.
                        --}}
                        <flux:radio.group name="draw_method" label="{{ __('¿Cómo se sortean los cruces?') }}">
                            @foreach (\App\Enums\DrawMethod::cases() as $method)
                                <flux:radio
                                    value="{{ $method->value }}"
                                    label="{{ $method->label() }}"
                                    description="{{ $method->description() }}"
                                    :checked="old('draw_method', \App\Enums\DrawMethod::Random->value) === $method->value"
                                />
                            @endforeach
                        </flux:radio.group>
                    </div>
                </template>

                <flux:text class="text-sm text-zinc-500">
                    {{ __('Si eliges eliminación directa, el número total de clasificados debe ser una potencia de 2 (2, 4, 8, 16...). Si eliges liga, se creará una nueva fase de liga con los clasificados, sin necesidad de que el número sea potencia de 2.') }}
                </flux:text>

                <flux:button type="submit" variant="primary">{{ __('Continuar') }}</flux:button>
            </form>
        </div>
    </div>
</x-layouts::app>
