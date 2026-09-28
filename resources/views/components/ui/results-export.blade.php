@props([
    'tournament',
    // Every fecha with at least one played match (MatchResultsReportService::playedRounds()).
    'rounds',
    // false = render only the modal -- see x-ui.programming-export's
    // showTrigger docblock, this is its sibling for played matches.
    'showTrigger' => true,
])

{{--
    "Exportar resultados": a modal to pick one or more fechas (or all of
    them) for MatchResultsPdfController::exportTournament() -- every
    category together, split by fecha. Same fetch-then-save download as
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
        selected: [],
        get roundsParam() {
            return this.all ? 'all' : [...this.selected].sort((a, b) => a - b).join(',');
        },
        async download() {
            if (this.roundsParam === '') return;

            this.exporting = true;

            const base = {{ \Illuminate\Support\Js::from($baseUrl) }};
            const url = base + (base.includes('?') ? '&' : '?') + 'rounds=' + this.roundsParam;
            const fileName = {{ \Illuminate\Support\Js::from($filePrefix) }} + (this.all ? '-todas-las-fechas' : '-fecha-' + this.roundsParam.replaceAll(',', '-')) + '.pdf';

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

    <flux:modal name="{{ $modalName }}" class="w-full max-w-sm">
        <div class="space-y-5">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Exportar resultados') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-white/60">
                    {{ __('Partidos ya jugados de todas las categorías en las fechas que elijas.') }}
                </flux:text>
            </div>

            <div class="space-y-2">
                <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-semibold dark:border-white/10">
                    <input type="checkbox" x-model="all" class="size-4 rounded accent-(--color-accent)">
                    {{ __('Todas las fechas') }}
                </label>

                <div class="grid grid-cols-3 gap-2" :class="all && 'opacity-50'">
                    @foreach ($rounds as $round)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-white/10">
                            <input type="checkbox" value="{{ $round }}" x-model.number="selected" x-bind:disabled="all" class="size-4 rounded accent-(--color-accent)">
                            {{ __('Fecha :number', ['number' => $round]) }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    icon="arrow-down-tray"
                    x-on:click="download()"
                    x-bind:disabled="! all && selected.length === 0"
                    :loading="true"
                    x-bind:data-loading="exporting"
                >
                    {{ __('Exportar PDF') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
