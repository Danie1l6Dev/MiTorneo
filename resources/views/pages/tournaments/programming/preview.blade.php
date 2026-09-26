<x-layouts::app :title="__('Vista previa de la programación')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Vista previa de la programación')" :subtitle="$roundTitle">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => $tournament->name, 'href' => route('tournaments.show', $tournament)],
                    ['label' => __('Programar fecha'), 'href' => route('tournaments.programming.edit', [$tournament, 'round' => $round])],
                    ['label' => __('Vista previa')],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        @if ($conflictCount > 0)
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="trans_choice('Hay :count partido con cruces: corrígelo para poder guardar.|Hay :count partidos con cruces: corrígelos para poder guardar.', $conflictCount, ['count' => $conflictCount])" />
        @else
            <flux:callout variant="success" icon="check-circle" :heading="__('Sin cruces. Revisa los horarios y guarda cuando estés conforme.')" />
        @endif

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Revisa los datos ingresados')">
                <ul class="list-disc space-y-1 ps-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </flux:callout>
        @endif

        <form method="POST" action="{{ route('tournaments.programming.store', $tournament) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="round" value="{{ $round }}">
            <input type="hidden" name="overwrite" value="{{ $overwrite ? 1 : 0 }}">

            @foreach ($groups as $group)
                <div class="space-y-3 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="sm">{{ $group['category']->name }}</flux:heading>

                        @if ($group['group'])
                            <flux:badge size="sm" color="zinc">{{ $group['group'] }}</flux:badge>
                        @endif
                    </div>

                    <div class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach ($group['items'] as $item)
                            @php $match = $item['match']; @endphp

                            <div class="space-y-2 py-3">
                                <div class="text-sm font-medium text-zinc-800 dark:text-white">
                                    {{ $match->homeTeam->name }}
                                    <span class="text-zinc-400 dark:text-white/40">vs</span>
                                    {{ $match->awayTeam->name }}
                                </div>

                                <div class="grid gap-3 sm:grid-cols-3">
                                    <flux:input
                                        name="matches[{{ $match->id }}][date]"
                                        type="date"
                                        size="sm"
                                        aria-label="{{ __('Día') }}"
                                        value="{{ $item['date'] }}"
                                    />

                                    <flux:input
                                        name="matches[{{ $match->id }}][time]"
                                        type="time"
                                        size="sm"
                                        aria-label="{{ __('Hora') }}"
                                        value="{{ $item['time'] }}"
                                    />

                                    <flux:select
                                        name="matches[{{ $match->id }}][venue_id]"
                                        size="sm"
                                        aria-label="{{ __('Cancha') }}"
                                        placeholder="{{ __('Sin cancha') }}"
                                    >
                                        @foreach ($venues as $venue)
                                            <flux:select.option value="{{ $venue->id }}" :selected="(string) $venue->id === (string) $item['venue_id']">
                                                {{ $venue->name }}
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>
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
                </div>
            @endforeach

            <flux:text class="text-xs text-zinc-500 dark:text-white/50">
                {{ __('Un partido sin día no se modifica. Una hora vacía significa «hora por definir».') }}
            </flux:text>

            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" variant="primary" icon="check">{{ __('Guardar programación') }}</flux:button>
                <flux:button :href="route('tournaments.programming.edit', [$tournament, 'round' => $round])" variant="ghost" wire:navigate>
                    {{ __('Volver') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
