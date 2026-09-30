@props([
    'tournament',
    'category',
    // Calendar days (Y-m-d, ascending) with a played match in $category
    // (MatchResultsReportService::playedDays()) -- bounds the pickers.
    'days',
])

{{--
    "Resultados por rango de fechas" for one category: a modal with a from/to
    date picker for MatchResultsPdfController::exportTournament() (?from=&to=
    &category=). Same fetch-then-save download as x-ui.results-export. Opened
    from a menu item of the calendar's "Exportar resultados" menu, which
    dispatches modal-show under this deterministic name -- see
    x-ui.programming-export's docblock for why a flux:modal.trigger must never
    be nested directly inside a flux:menu.item.
--}}
@php
    $modalName = 'results-range-export-'.$tournament->id.'-'.$category->id;
    $baseUrl = route('tournaments.results.pdf', [$tournament, 'category' => $category->id]);
    $filePrefix = 'resultados-'.str($category->name)->slug();
    $first = $days[0] ?? '';
    $last = $days === [] ? '' : end($days);
@endphp

<div
    class="inline-block"
    x-data="{
        exporting: false,
        dateFrom: {{ \Illuminate\Support\Js::from($first) }},
        dateTo: {{ \Illuminate\Support\Js::from($last) }},
        get validRange() {
            return this.dateFrom !== '' && this.dateTo !== '' && this.dateFrom <= this.dateTo;
        },
        async download() {
            if (! this.validRange) return;

            this.exporting = true;

            const base = {{ \Illuminate\Support\Js::from($baseUrl) }};
            const url = base + '&from=' + this.dateFrom + '&to=' + this.dateTo;
            const fileName = {{ \Illuminate\Support\Js::from($filePrefix) }} + '-del-' + this.dateFrom + '-al-' + this.dateTo + '.pdf';

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
    <flux:modal name="{{ $modalName }}" class="w-full max-w-md">
        <div class="space-y-5">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Resultados por rango de fechas') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-white/60">
                    {{ __('Partidos ya jugados de :category entre las fechas que elijas, sin importar su jornada o fase (incluye cuartos, semifinal y final).', ['category' => $category->name]) }}
                </flux:text>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <label class="block space-y-1 text-sm font-medium">
                    <span>{{ __('Desde') }}</span>
                    <input type="date" x-model="dateFrom" min="{{ $first }}" max="{{ $last }}" class="w-full rounded-lg border border-zinc-200 bg-transparent px-3 py-2 dark:border-white/10 dark:[color-scheme:dark]">
                </label>
                <label class="block space-y-1 text-sm font-medium">
                    <span>{{ __('Hasta') }}</span>
                    <input type="date" x-model="dateTo" min="{{ $first }}" max="{{ $last }}" class="w-full rounded-lg border border-zinc-200 bg-transparent px-3 py-2 dark:border-white/10 dark:[color-scheme:dark]">
                </label>
            </div>

            <p class="text-xs text-red-600 dark:text-red-400" x-show="dateFrom !== '' && dateTo !== '' && dateFrom > dateTo" x-cloak>{{ __('La fecha inicial no puede ser posterior a la final.') }}</p>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    icon="arrow-down-tray"
                    x-on:click="download()"
                    x-bind:disabled="! validRange"
                    :loading="true"
                    x-bind:data-loading="exporting"
                >
                    {{ __('Exportar PDF') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
