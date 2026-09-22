<x-layouts::app :title="__('Agregar jugador')">
    <div
        class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up"
        x-data="{
            documentNumber: '{{ old('document_number') }}',
            fullName: '{{ old('full_name') }}',
            birthDate: '{{ old('birth_date') }}',
            gender: '{{ old('gender') }}',
            checking: false,
            searched: false,
            found: false,
            foundTeams: [],
            currentClub: null,
            nameTouched: false,
            birthDateTouched: false,
            genderTouched: false,
            thisClubId: {{ $team->club_id ?? 'null' }},
            get foundMessage() {
                const teams = this.foundTeams.map(t => t.category ? `${t.name} (${t.category})` : t.name).join(', ');
                let message = `{{ __(':name ya está registrado -- planteles actuales: :teams.') }}`
                    .replace(':name', this.fullName)
                    .replace(':teams', teams);
                if (this.currentClub && this.currentClub.id !== this.thisClubId) {
                    message += ' ' + `{{ __('Pertenece al club :club -- agregarlo aquí lo moverá a este club.') }}`
                        .replace(':club', this.currentClub.name);
                }
                if (!this.birthDate) {
                    message += ' ' + `{{ __('Todavía no tiene fecha de nacimiento cargada -- puede completarla abajo para sumarlo a este plantel.') }}`;
                }
                return message;
            },
            async checkDocument() {
                const value = this.documentNumber.trim();
                if (value.length < 3) {
                    this.checking = false;
                    this.searched = false;
                    this.found = false;
                    this.foundTeams = [];
                    this.currentClub = null;
                    return;
                }
                this.checking = true;
                try {
                    const response = await fetch(`{{ route('players.search') }}?document_number=${encodeURIComponent(value)}`, {
                        headers: { Accept: 'application/json' },
                    });
                    const data = await response.json();
                    this.found = data.found;
                    if (data.found) {
                        // No pisamos lo que ya está escribiendo si tocó el campo mientras esperaba la respuesta.
                        if (!this.nameTouched) this.fullName = data.player.full_name;
                        if (!this.birthDateTouched) this.birthDate = data.player.birth_date ?? '';
                        if (!this.genderTouched) this.gender = data.player.gender ?? '';
                        this.foundTeams = data.teams;
                        this.currentClub = data.current_club;
                    } else {
                        this.foundTeams = [];
                        this.currentClub = null;
                    }
                } finally {
                    this.checking = false;
                    this.searched = true;
                }
            },
        }"
    >
        <x-ui.page-header :title="__('Agregar jugador')" :subtitle="$team->name" />

        <flux:callout variant="secondary" icon="information-circle" :heading="__('¿Ya juega en otro plantel tuyo?')">
            {{ __('Si ya está registrado con este mismo documento en otro club o categoría, se vincula directo a este plantel y sus datos se autocompletan -- pueden corregirse aquí mismo si hace falta.') }}
        </flux:callout>

        <template x-if="found">
            <flux:callout class="animate-fade-in-up" variant="warning" icon="exclamation-triangle" :heading="__('Ya está registrado')">
                <span x-text="foundMessage"></span>
            </flux:callout>
        </template>

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('teams.players.store', $team) }}" class="space-y-6">
                @csrf

                <flux:input
                    name="document_number"
                    label="{{ __('Documento') }}"
                    description="{{ __('La forma en la que lo reconocemos si ya está cargado') }}"
                    x-model="documentNumber"
                    @input="nameTouched = false; birthDateTouched = false; genderTouched = false"
                    @input.debounce.500ms="checkDocument()"
                    autofocus
                />

                <div class="flex items-center gap-1.5 text-sm" x-show="documentNumber.trim().length >= 3" x-cloak>
                    <template x-if="checking">
                        <span class="flex items-center gap-1.5 text-zinc-500 dark:text-white/50 animate-shimmer-pulse">
                            <flux:icon.loading variant="mini" />
                            {{ __('Buscando en la base de datos...') }}
                        </span>
                    </template>
                    <template x-if="!checking && searched && !found">
                        <span class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400 animate-fade-in-up">
                            <flux:icon.check-circle variant="mini" />
                            {{ __('No hay ningún jugador con este documento -- se cargará como nuevo.') }}
                        </span>
                    </template>
                </div>

                <flux:input
                    name="full_name"
                    label="{{ __('Nombre completo') }}"
                    description="{{ __('Si el documento ya está cargado, se autocompleta -- puede corregirse antes de guardar') }}"
                    x-model="fullName"
                    @input="nameTouched = true"
                    required
                />

                <flux:input
                    type="date"
                    name="birth_date"
                    label="{{ __('Fecha de nacimiento') }}"
                    description="{{ __('Opcional, pero se necesita para poder sumarlo a otra categoría más adelante') }}"
                    x-model="birthDate"
                    @input="birthDateTouched = true"
                />

                <flux:select
                    name="gender"
                    label="{{ __('Género') }}"
                    description="{{ __('Opcional. En categorías mixtas, habilita años extra permitidos para mujeres') }}"
                    x-model="gender"
                    @change="genderTouched = true"
                >
                    <flux:select.option value="">{{ __('Sin especificar') }}</flux:select.option>
                    @foreach (\App\Enums\Gender::cases() as $genderOption)
                        <flux:select.option value="{{ $genderOption->value }}">
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
