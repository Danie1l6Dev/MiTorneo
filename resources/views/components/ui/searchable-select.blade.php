{{--
    Un <select> normal, pero con una barra de búsqueda que filtra las
    opciones mientras se escribe -- para listas largas (canchas, árbitros)
    donde desplazarse por un <select> nativo es incómodo. Mismo patrón que
    ya usa pages/players/transfer.blade.php para elegir el club destino,
    llevado a un componente reutilizable.

    Cerrado por defecto, como cualquier desplegable: el botón muestra lo
    elegido (o el placeholder) y solo al hacer click se abre el panel
    flotante con la búsqueda y la lista -- nunca ocupa espacio de la
    página mientras está cerrado.

    El panel se teletransporta (x-teleport) al final de <body> y se
    posiciona a mano (position: fixed + coordenadas del botón) en vez de
    ir con position: absolute colgando de este mismo div -- cualquier
    tarjeta con el efecto de vidrio (glass-panel, backdrop-filter) crea su
    propio contexto de apilamiento en CSS, así que un z-index de acá adentro
    nunca puede ganarle a algo de AFUERA de esa tarjeta (por eso el panel
    quedaba tapado por "Eliminar partido"). Teletransportarlo lo saca de
    ese contexto por completo.

    Sigue siendo un <input type="hidden"> normal por debajo, así que
    funciona con cualquier <form> tal cual (incluido un listener
    x-on:change/x-on:input en un ancestro, como el de "Programar fecha" --
    pick()/clear() disparan esos eventos a mano sobre el hidden porque
    :value no lo hace solo).

    Sin JS (Flux, en su version gratuita, no trae un select con buscador --
    esta es la version propia).
--}}
@props([
    'name',
    'options',
    'selected' => null,
    'label' => null,
    'placeholder' => null,
    'searchPlaceholder' => null,
    'emptyMessage' => null,
    'disabled' => false,
])

@php
    $normalizedOptions = collect($options)
        ->map(fn ($option) => is_array($option) ? $option : ['id' => $option->id, 'label' => (string) $option])
        ->values();

    $placeholder ??= __('Ninguno');
    $searchPlaceholder ??= __('Buscar...');
    $emptyMessage ??= __('Nada coincide con la búsqueda.');
@endphp

@if ($disabled)
    @php
        $currentLabel = $normalizedOptions->first(fn (array $option): bool => (string) $option['id'] === (string) $selected)['label'] ?? null;
    @endphp

    <flux:field>
        @if ($label)
            <flux:label>{{ $label }}</flux:label>
        @endif

        <input type="hidden" name="{{ $name }}" value="{{ $selected }}" disabled>

        <div class="flex h-10 items-center rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white px-3 text-sm text-zinc-500 shadow-xs dark:border-white/10 dark:bg-white/[7%] dark:text-zinc-400">
            {{ $currentLabel ?? $placeholder }}
        </div>
    </flux:field>
@else
    <div
        {{ $attributes->class('relative') }}
        x-data="{
            open: false,
            options: {{ \Illuminate\Support\Js::from($normalizedOptions) }},
            value: {{ \Illuminate\Support\Js::from($selected !== null ? (string) $selected : '') }},
            search: '',
            panelStyle: '',
            get chosen() {
                return this.options.find((option) => String(option.id) === String(this.value)) ?? null;
            },
            normalize(text) {
                return String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
            },
            get filtered() {
                const query = this.normalize(this.search);

                return query === '' ? this.options : this.options.filter((option) => this.normalize(option.label).includes(query));
            },
            position() {
                const rect = this.$refs.trigger.getBoundingClientRect();

                this.panelStyle = `top:${rect.bottom + 4}px; left:${rect.left}px; width:${rect.width}px;`;
            },
            openPanel() {
                this.position();
                this.open = true;
                this.$nextTick(() => this.$refs.search?.focus());
            },
            pick(id) {
                this.value = String(id);
                this.search = '';
                this.open = false;
                this.$nextTick(() => this.$refs.hidden.dispatchEvent(new Event('input', { bubbles: true })));
            },
            clear() {
                this.value = '';
                this.search = '';
                this.$nextTick(() => this.$refs.hidden.dispatchEvent(new Event('input', { bubbles: true })));
            },
        }"
        x-on:click.outside="open = false"
        x-on:keydown.escape="open = false"
        x-on:resize.window="if (open) position()"
        x-on:scroll.window="if (open) position()"
    >
        @if ($label)
            <flux:label>{{ $label }}</flux:label>
        @endif

        <input type="hidden" name="{{ $name }}" x-ref="hidden" :value="value">

        {{-- El desplegable cerrado: muestra lo elegido (o el placeholder) --
             nunca ocupa más espacio que un input normal. --}}
        <button
            type="button"
            x-ref="trigger"
            x-on:click="open ? (open = false) : openPanel()"
            :aria-expanded="open"
            class="flex h-10 w-full items-center justify-between gap-2 rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white px-3 text-start text-sm shadow-xs dark:border-white/10 dark:bg-white/10"
        >
            <span class="truncate" :class="chosen === null && 'text-zinc-400 dark:text-zinc-400'" x-text="chosen ? chosen.label : {{ \Illuminate\Support\Js::from($placeholder) }}"></span>

            <span class="flex shrink-0 items-center gap-1">
                <span x-show="chosen !== null" x-cloak x-on:click.stop="clear()" class="rounded p-0.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-white/10 dark:hover:text-white">
                    <flux:icon.x-mark variant="micro" class="size-3.5" />
                </span>
                <flux:icon.chevron-down variant="micro" class="size-4 text-zinc-400" />
            </span>
        </button>

        {{-- Teletransportado a <body>: ver la nota de arriba sobre por qué no
             puede ir con position: absolute colgando de este mismo div. --}}
        <template x-teleport="body">
            <div
                x-show="open"
                x-cloak
                x-on:click.stop
                :style="panelStyle"
                class="fixed z-50 space-y-2 rounded-lg border border-zinc-200 bg-white p-2 shadow-lg dark:border-white/10 dark:bg-zinc-900"
            >
                <flux:input
                    type="search"
                    x-ref="search"
                    x-model="search"
                    icon="magnifying-glass"
                    placeholder="{{ $searchPlaceholder }}"
                    autocomplete="off"
                    size="sm"
                />

                <div class="max-h-56 overflow-y-auto rounded-lg border border-zinc-100 dark:border-white/5">
                    <template x-for="option in filtered" :key="option.id">
                        <button
                            type="button"
                            x-on:click="pick(option.id)"
                            class="block w-full border-b border-zinc-100 px-3 py-2 text-start text-sm text-zinc-800 last:border-b-0 hover:bg-zinc-50 dark:border-white/5 dark:text-white dark:hover:bg-white/10"
                            x-text="option.label"
                        ></button>
                    </template>

                    <div x-show="filtered.length === 0" x-cloak class="px-3 py-3 text-sm text-zinc-500 dark:text-white/60">
                        {{ $emptyMessage }}
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif
