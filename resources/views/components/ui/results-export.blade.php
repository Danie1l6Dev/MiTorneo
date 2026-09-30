@props([
    'tournament',
    // Every jornada with at least one played match (MatchResultsReportService::playedRounds()).
    'rounds',
    // Calendar days (Y-m-d) with matches to export -- the "Por día" mode. An
    // empty list hides that mode.
    'days' => [],
    // false = render only the modal -- see x-ui.programming-export's
    // showTrigger docblock, this is its sibling for played matches.
    'showTrigger' => true,
])

{{--
    "Exportar resultados": a modal to pick one or more jornadas (or all of
    them) for MatchResultsPdfController::exportTournament() -- every
    category together, split by jornada. Same fetch-then-save download as
    x-ui.programming-export (which this is a sibling of, for played matches
    instead of pending ones), so the loading state follows the real response.

    $modalName is deterministic -- see x-ui.programming-export's docblock for
    why, and for why a flux:modal.trigger must never be nested directly
    inside a flux:menu.item.
--}}
@php
    $modalName = 'results-export-'.$tournament->id;
    $baseUrl = route('tournaments.results.pdf', $tournament);
    $filePrefix = 'resultados-'.str($tournament->name)->slug();
@endphp

<div
    class="inline-block"
    x-data="{
        exporting: false,
        all: false,
        mode: 'rounds',
        selected: [],
        dateFrom: {{ \Illuminate\Support\Js::from($days[0] ?? '') }},
        dateTo: {{ \Illuminate\Support\Js::from($days === [] ? '' : end($days)) }},
        get roundsParam() {
            return this.all ? 'all' : [...this.selected].sort((a, b) => a - b).join(',');
        },
        get validRange() {
            return this.dateFrom !== '' && this.dateTo !== '' && this.dateFrom <= this.dateTo;
        },
        get query() {
            if (this.mode === 'days') {
                return this.validRange ? 'from=' + this.dateFrom + '&to=' + this.dateTo : '';
            }

            return this.roundsParam === '' ? '' : 'rounds=' + this.roundsParam;
        },
        get fileSuffix() {
            if (this.mode === 'days') {
                return '-del-' + this.dateFrom + '-al-' + this.dateTo;
            }

            return this.all ? '-todas-las-jornadas' : '-jornada-' + this.roundsParam.replaceAll(',', '-');
        },
        async download() {
            if (this.query === '') return;

            this.exporting = true;

            const base = {{ \Illuminate\Support\Js::from($baseUrl) }};
            const url = base + (base.includes('?') ? '&' : '?') + this.query;
            const fileName = {{ \Illuminate\Support\Js::from($filePrefix) }} + this.fileSuffix + '.pdf';

            try {
                const response = await fetch(url);

                if (! response.ok) {
                    throw new Error('export failed');
                }

                const blob = await response.blob();
                const objectUrl = URL.createObjectURL(blob);

                const link = document.createElement('a');
                link.href = objectUrl;
                link.download = fileName;
                link.click();

                URL.revokeObjectURL(objectUrl);
            } catch (error) {
                alert('{{ __('No se pudo generar el PDF. Intenta de nuevo.') }}');
            } finally {
                this.exporting = false;
            }
        },
    }"
>
    @if ($showTrigger)
        <flux:modal.trigger name="{{ $modalName }}">
            <flux:button icon="document-text" {{ $attributes }}>{{ __('Resultados') }}</flux:button>
        </flux:modal.trigger>
    @endif

    <flux:modal name="{{ $modalName }}" class="w-full max-w-md">
        <div class="space-y-5">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Exportar resultados') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-white/60">
                    {{ __('Partidos ya jugados de todas las categorías en las jornadas que elijas.') }}
                </flux:text>
            </div>

            @if ($days !== [])
                <div class="grid grid-cols-2 gap-1 rounded-lg bg-zinc-100 p-1 text-sm font-medium dark:bg-white/10">
                    <button type="button" x-on:click="mode = 'rounds'" class="rounded-md px-3 py-1.5" x-bind:class="mode === 'rounds' ? 'bg-white shadow-sm dark:bg-zinc-700' : 'text-zinc-500 dark:text-white/60'">{{ __('Por jornada') }}</button>
                    <button type="button" x-on:click="mode = 'days'" class="rounded-md px-3 py-1.5" x-bind:class="mode === 'days' ? 'bg-white shadow-sm dark:bg-zinc-700' : 'text-zinc-500 dark:text-white/60'">{{ __('Por día') }}</button>
                </div>
            @endif

            <div class="space-y-2" x-show="mode === 'rounds'">
                <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-semibold dark:border-white/10">
                    <input type="checkbox" x-model="all" class="size-4 rounded accent-(--color-accent)">
                    {{ __('Todas las jornadas') }}
                </label>

                <div class="grid grid-cols-3 gap-2" :class="all && 'opacity-50'">
                    @foreach ($rounds as $round)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-white/10">
                            <input type="checkbox" value="{{ $round }}" x-model.number="selected" x-bind:disabled="all" class="size-4 rounded accent-(--color-accent)">
                            {{ __('Jornada :number', ['number' => $round]) }}
                        </label>
                    @endforeach
                </div>
            </div>

            @if ($days !== [])
                <div class="space-y-2" x-show="mode === 'days'" x-cloak>
                    <flux:text class="text-zinc-500 dark:text-white/60">
                        {{ __('Elige el rango de días de calendario: se incluyen los partidos de todas las jornadas que caigan dentro, agrupados por día y por categoría.') }}
                    </flux:text>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="block space-y-1 text-sm font-medium">
                            <span>{{ __('Desde') }}</span>
                            <input type="date" x-model="dateFrom" min="{{ $days[0] ?? '' }}" max="{{ $days === [] ? '' : end($days) }}" class="w-full rounded-lg border border-zinc-200 bg-transparent px-3 py-2 dark:border-white/10 dark:[color-scheme:dark]">
                        </label>
                        <label class="block space-y-1 text-sm font-medium">
                            <span>{{ __('Hasta') }}</span>
                            <input type="date" x-model="dateTo" min="{{ $days[0] ?? '' }}" max="{{ $days === [] ? '' : end($days) }}" class="w-full rounded-lg border border-zinc-200 bg-transparent px-3 py-2 dark:border-white/10 dark:[color-scheme:dark]">
                        </label>
                    </div>

                    <p class="text-xs text-red-600 dark:text-red-400" x-show="dateFrom !== '' && dateTo !== '' && dateFrom > dateTo" x-cloak>{{ __('La fecha inicial no puede ser posterior a la final.') }}</p>
                    <p class="text-xs text-zinc-500 dark:text-white/60">{{ trans_choice('Hay partidos en :count día entre el :first y el :last.|Hay partidos en :count días entre el :first y el :last.', count($days), ['first' => \Illuminate\Support\Carbon::parse($days[0])->format('d/m/Y'), 'last' => \Illuminate\Support\Carbon::parse(end($days))->format('d/m/Y')]) }}</p>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    icon="arrow-down-tray"
                    x-on:click="download()"
                    x-bind:disabled="query === ''"
                    :loading="true"
                    x-bind:data-loading="exporting"
                >
                    {{ __('Exportar PDF') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
