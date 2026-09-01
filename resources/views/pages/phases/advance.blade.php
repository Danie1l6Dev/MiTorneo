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

                <flux:select name="type" label="{{ __('Tipo de fase') }}" x-model="type">
                    @php $currentType = old('type', \App\Enums\CompetitionPhaseType::Knockout->value); @endphp

                    @foreach (\App\Enums\CompetitionPhaseType::cases() as $type)
                        <flux:select.option value="{{ $type->value }}" :selected="$type->value === $currentType">
                            {{ $type->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

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
                    <flux:callout variant="secondary" icon="information-circle" :heading="__('Clasifican 2 equipos de cada tabla, automáticamente.')" />
                </div>

                <div x-show="type === '{{ \App\Enums\CompetitionPhaseType::Final->value }}'" x-cloak>
                    <flux:callout variant="secondary" icon="information-circle" :heading="__('Clasifica 1 equipo de cada tabla, automáticamente.')" />
                </div>

                <flux:text class="text-sm text-zinc-500">
                    {{ __('Si eliges eliminación directa, el número total de clasificados debe ser una potencia de 2 (2, 4, 8, 16...) y los cruces se sortearán al azar entre todos los clasificados, sin importar de qué grupo vengan. Si eliges liga, se creará una nueva fase de liga con los clasificados, sin necesidad de que el número sea potencia de 2.') }}
                </flux:text>

                <flux:button type="submit" variant="primary">{{ __('Continuar') }}</flux:button>
            </form>
        </div>
    </div>
</x-layouts::app>
