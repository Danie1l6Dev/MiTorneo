{{--
    The proposal panel of "Programar fecha": every match of the chosen category
    in play order, each with the day / time / cancha the fields above produce
    (still editable one by one) and whatever clashes that slot has. Rendered
    with the page and re-rendered on its own by
    TournamentProgrammingController::preview() -- so it only uses plain HTML
    controls, which behave the same when swapped in as a fragment.
--}}
@php
    $control = 'w-full rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-800 shadow-xs focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500/30 dark:border-white/10 dark:bg-white/5 dark:text-white';
    $blocked = ! $preview['hasProposal'] || $preview['conflictCount'] > 0;
@endphp

<div class="space-y-4">
    @if (! $preview['hasProposal'])
        <flux:callout variant="secondary" icon="information-circle" :heading="__('Elige el día para ver la propuesta de horarios.')">
            {{ __('Estos son los partidos de la categoría. Al llenar los campos de arriba, cada uno recibe su día, hora y cancha; luego puedes ajustarlos uno por uno.') }}
        </flux:callout>
    @elseif ($preview['conflictCount'] > 0)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="trans_choice('Hay :count partido con cruces: corrígelo para poder guardar.|Hay :count partidos con cruces: corrígelos para poder guardar.', $preview['conflictCount'], ['count' => $preview['conflictCount']])" />
    @else
        <flux:callout variant="success" icon="check-circle" :heading="__('Sin cruces. Revisa los horarios y guarda cuando estés conforme.')" />
    @endif

    @if ($preview['freeFrom'])
        {{-- The chosen cancha already has matches that day (say, another category's):
             offer to start where they end. useStart() lives on the page's Alpine root. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
            <span>{{ __('Esa cancha ya tiene partidos ese día: queda libre desde las :time.', ['time' => $preview['freeFrom']['label']]) }}</span>

            <flux:button type="button" size="sm" variant="ghost" icon="clock" x-on:click="useStart('{{ $preview['freeFrom']['time'] }}')">
                {{ __('Empezar a las :time', ['time' => $preview['freeFrom']['label']]) }}
            </flux:button>
        </div>
    @endif

    <div class="divide-y divide-zinc-100 rounded-2xl border border-zinc-200 px-5 dark:divide-white/5 dark:border-white/10 glass-panel">
        @foreach ($preview['items'] as $item)
            @php $match = $item['match']; @endphp

            <div class="space-y-2 py-3">
                <div class="flex flex-wrap items-center gap-2 text-sm font-medium text-zinc-800 dark:text-white">
                    <span>{{ $match->homeTeam->name }}</span>
                    <span class="text-zinc-400 dark:text-white/40">vs</span>
                    <span>{{ $match->awayTeam->name }}</span>

                    @if ($item['group'])
                        <flux:badge size="sm" color="zinc">{{ $item['group'] }}</flux:badge>
                    @endif
                </div>

                @if ($match->scheduleSummary())
                    <div class="text-xs text-zinc-500 dark:text-white/50">
                        {{ __('Actualmente') }}: {{ $match->scheduleSummary() }}
                    </div>
                @endif

                <div class="grid gap-3 sm:grid-cols-3">
                    <input
                        type="date"
                        name="matches[{{ $match->id }}][date]"
                        value="{{ $item['date'] }}"
                        aria-label="{{ __('Día') }}"
                        class="{{ $control }}"
                    >

                    <input
                        type="time"
                        name="matches[{{ $match->id }}][time]"
                        value="{{ $item['time'] }}"
                        aria-label="{{ __('Hora') }}"
                        class="{{ $control }}"
                    >

                    <select name="matches[{{ $match->id }}][venue_id]" aria-label="{{ __('Cancha') }}" class="{{ $control }}">
                        <option value="">{{ __('Sin cancha') }}</option>
                        @foreach ($venues as $venue)
                            <option value="{{ $venue->id }}" @selected((string) $venue->id === (string) $item['venue_id'])>{{ $venue->name }}</option>
                        @endforeach
                    </select>
                </div>

                @foreach ($item['conflicts'] as $conflict)
                    <div class="flex items-start gap-1.5 text-xs text-red-600 dark:text-red-400">
                        <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                        <span>{{ $conflict }}</span>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <flux:text class="text-xs text-zinc-500 dark:text-white/50">
        {{ __('Un partido sin día no se modifica. Una hora vacía significa «hora por definir».') }}
    </flux:text>

    <flux:button type="submit" variant="primary" icon="check" :disabled="$blocked">
        {{ __('Guardar programación') }}
    </flux:button>
</div>
