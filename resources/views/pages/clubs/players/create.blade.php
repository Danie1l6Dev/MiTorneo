@php
    // Category name => group name (or "Sin grupo") => teams -- same
    // organizing principle used everywhere else in the catalog.
    $teamsByCategory = $teams
        ->groupBy('category.name')
        ->map(fn ($teams) => $teams->groupBy(fn ($team) => $team->group->name ?? __('Sin grupo')));
@endphp

<x-layouts::app :title="__('Agregar jugador')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up" x-data="{ birthDate: '{{ old('birth_date') }}', gender: '{{ old('gender') }}' }">
        <x-ui.page-header :title="__('Agregar jugador')" :subtitle="$club->name" />

        <flux:callout variant="secondary" icon="information-circle" :heading="__('¿Ya juega en otro plantel tuyo?')">
            {{ __('Si ya está registrado con este mismo documento en otro club/categoría tuya, se vincula a lo que marques acá -- no hace falta volver a cargar sus datos.') }}
        </flux:callout>

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('clubs.players.store', $club) }}" class="space-y-6">
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
                    description="{{ __('Obligatoria acá: habilita las categorías en las que puede jugar según su edad') }}"
                    x-model="birthDate"
                    required
                />

                <flux:select
                    name="gender"
                    label="{{ __('Género') }}"
                    description="{{ __('Opcional. En categorías mixtas, habilita años extra permitidos para mujeres') }}"
                    x-model="gender"
                >
                    <flux:select.option value="">{{ __('Sin especificar') }}</flux:select.option>
                    @foreach (\App\Enums\Gender::cases() as $genderOption)
                        <flux:select.option value="{{ $genderOption->value }}" :selected="$genderOption->value === old('gender')">
                            {{ $genderOption->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <div class="space-y-4">
                    <flux:label>{{ __('¿En qué planteles lo inscribes?') }}</flux:label>

                    <template x-if="!birthDate">
                        <flux:text class="text-sm text-amber-500">
                            {{ __('Carga la fecha de nacimiento para habilitar las categorías correspondientes.') }}
                        </flux:text>
                    </template>

                    @if ($teamsByCategory->isEmpty())
                        <x-ui.empty-state icon="shield-check" :message="__('Este club todavía no tiene ningún plantel -- crea uno primero.')" />
                    @endif

                    @foreach ($teamsByCategory as $categoryName => $teamsByGroup)
                        @php $categoryForHeader = $teamsByGroup->first()->first()->category; @endphp

                        <div class="space-y-1 rounded-xl border border-zinc-200 p-4 dark:border-white/10">
                            <div class="mb-1 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                    {{ $categoryName }}
                                </span>

                                <x-ui.category-age-hint :category="$categoryForHeader" />
                            </div>

                            @foreach ($teamsByGroup as $groupName => $groupTeams)
                                @foreach ($groupTeams as $team)
                                    @php
                                        $byTo = $team->category->birth_year_to;
                                        $femaleExtra = (int) ($team->category->female_extra_birth_years ?? 0);
                                        $disabledExpr = $byTo === null
                                            ? '!birthDate'
                                            : "!birthDate || parseInt(birthDate.split('-')[0]) < ({$byTo} - (gender === 'female' ? {$femaleExtra} : 0))";
                                        $label = $team->name;
                                        if ($groupTeams->count() > 1 || $teamsByGroup->count() > 1) {
                                            $label .= ' — '.$groupName;
                                        }
                                    @endphp

                                    <flux:checkbox
                                        name="team_ids[]"
                                        value="{{ $team->id }}"
                                        label="{{ $label }}"
                                        x-bind:disabled="{{ $disabledExpr }}"
                                        :checked="in_array($team->id, (array) old('team_ids', []))"
                                    />
                                @endforeach
                            @endforeach
                        </div>
                    @endforeach

                    @error('team_ids')
                        <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                    @enderror
                </div>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Agregar jugador') }}</flux:button>
                    <flux:button :href="route('clubs.show', $club)" variant="ghost" wire:navigate>
                        {{ __('Cancelar') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
