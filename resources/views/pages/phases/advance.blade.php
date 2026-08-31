<x-layouts::app :title="__('Pasar a la siguiente fase')">
    <div class="mx-auto w-full max-w-xl space-y-6">
        <div>
            <flux:button :href="route('phases.show', $phase)" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
                {{ $phase->name }}
            </flux:button>

            <flux:heading size="xl" class="mt-2">{{ __('Pasar a la siguiente fase') }}</flux:heading>
            <flux:text class="mt-1">{{ $phase->category->name }}</flux:text>
        </div>

        @if (session('error') || $errors->any())
            <flux:callout variant="danger" icon="exclamation-circle" :heading="$errors->first() ?? session('error')" />
        @endif

        <flux:card class="space-y-2">
            <flux:heading size="sm">{{ __('Tablas actuales') }}</flux:heading>

            @foreach ($tables as $table)
                <flux:text class="text-sm">
                    {{ $table['label'] }}: {{ count($table['rows']) }} {{ __('equipos') }}
                </flux:text>
            @endforeach
        </flux:card>

        <form method="POST" action="{{ route('phases.advance.store', $phase) }}" class="space-y-6">
            @csrf

            <flux:input
                name="name"
                label="{{ __('Nombre de la nueva fase') }}"
                value="{{ old('name') }}"
                placeholder="{{ __('Semifinales') }}"
                required
                autofocus
            />

            <flux:select name="type" label="{{ __('Tipo de fase') }}">
                @php $currentType = old('type', \App\Enums\CompetitionPhaseType::Knockout->value); @endphp

                @foreach (\App\Enums\CompetitionPhaseType::cases() as $type)
                    <flux:select.option value="{{ $type->value }}" :selected="$type->value === $currentType">
                        {{ $type->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                name="qualifiers_per_table"
                type="number"
                min="1"
                label="{{ count($tables) > 1 ? __('¿Cuántos equipos clasifican de cada grupo?') : __('¿Cuántos equipos clasifican de la tabla?') }}"
                value="{{ old('qualifiers_per_table', 2) }}"
            />

            <flux:text class="text-sm text-zinc-500">
                {{ __('Si eliges una fase eliminatoria, el número total de clasificados debe ser una potencia de 2 (2, 4, 8, 16...) y los cruces se sortearán al azar entre todos los clasificados, sin importar de qué grupo vengan. Si eliges liga o fase de grupos, se creará una nueva fase de liga con los clasificados, sin necesidad de que el número sea potencia de 2.') }}
            </flux:text>

            <flux:button type="submit" variant="primary">{{ __('Continuar') }}</flux:button>
        </form>
    </div>
</x-layouts::app>
