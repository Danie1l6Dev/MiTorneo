<x-layouts::app :title="__('Agregar jugador')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Agregar jugador')" :subtitle="$team->name" />

        <flux:callout variant="secondary" icon="information-circle" :heading="__('¿Ya juega en otro plantel tuyo?')">
            {{ __('Si ya está registrado con este mismo documento en otro club/categoría tuya, se vincula directo a este plantel -- no hace falta volver a cargar sus datos.') }}
        </flux:callout>

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('teams.players.store', $team) }}" class="space-y-6">
                @csrf

                <flux:input
                    name="document_number"
                    label="{{ __('Documento') }}"
                    description="{{ __('La forma en la que lo reconocemos si ya está cargado') }}"
                    value="{{ old('document_number') }}"
                    autofocus
                />

                <flux:input
                    name="full_name"
                    label="{{ __('Nombre completo') }}"
                    description="{{ __('Se ignora si el documento ya corresponde a alguien registrado') }}"
                    value="{{ old('full_name') }}"
                    required
                />

                <flux:input
                    type="date"
                    name="birth_date"
                    label="{{ __('Fecha de nacimiento') }}"
                    description="{{ __('Opcional, pero se necesita para poder sumarlo a otra categoría más adelante') }}"
                    value="{{ old('birth_date') }}"
                />

                <flux:select
                    name="gender"
                    label="{{ __('Género') }}"
                    description="{{ __('Opcional. En categorías mixtas, habilita años extra permitidos para mujeres') }}"
                >
                    <flux:select.option value="">{{ __('Sin especificar') }}</flux:select.option>
                    @foreach (\App\Enums\Gender::cases() as $genderOption)
                        <flux:select.option value="{{ $genderOption->value }}" :selected="$genderOption->value === old('gender')">
                            {{ $genderOption->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    type="number"
                    name="jersey_number"
                    label="{{ __('Dorsal en este plantel') }}"
                    description="{{ __('Opcional -- puede ser distinto al que tiene en otro plantel') }}"
                    min="1"
                    max="{{ \App\Http\Requests\PlayerRequest::MAX_JERSEY_NUMBER }}"
                    value="{{ old('jersey_number') }}"
                />

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Agregar jugador') }}</flux:button>
                    <flux:button :href="route('teams.show', $team)" variant="ghost" wire:navigate>
                        {{ __('Cancelar') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
