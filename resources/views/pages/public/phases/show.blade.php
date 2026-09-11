<x-layouts::public :title="$phase->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$phase->name">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => $tournament->name, 'href' => route('public.tournaments.show', $tournament)],
                    ['label' => $category->name, 'href' => route('public.tournaments.categories.show', [$tournament, $category])],
                    ['label' => $phase->name],
                ]" />
            </x-slot:breadcrumbs>

            <div class="mt-1 flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="$phase->type->color()">{{ $phase->type->label() }}</flux:badge>
            </div>
        </x-ui.page-header>

        <flux:separator variant="subtle" />

        @if ($phase->type === \App\Enums\CompetitionPhaseType::League)
            {{-- syncUrl() keeps the address bar matching whichever tab is selected (via replaceState,
                 never a new history entry), so navigating away (e.g. a category/tournament breadcrumb)
                 and back restores the tab the visitor actually had open instead of whatever '?view='/hash
                 happened to be in the URL from the page's original load. See phases/show.blade.php (the
                 admin equivalent) for the full reasoning -- kept identical here on purpose. --}}
            <div
                x-data="{
                    section: (() => {
                        const view = new URLSearchParams(window.location.search).get('view');

                        if (['goal', 'assist', 'yellow_card', 'red_card'].includes(view)) return view;

                        return window.location.hash.startsWith('#calendario') ? 'calendario' : 'tabla';
                    })(),
                    statGroup: new URLSearchParams(window.location.search).get('group') ?? 'all',
                    statScope: new URLSearchParams(window.location.search).get('phase') === 'all' ? 'all' : 'league',
                    syncUrl() {
                        const url = new URL(window.location.href);
                        const isStatType = ['goal', 'assist', 'yellow_card', 'red_card'].includes(this.section);

                        url.searchParams.delete('view');
                        url.searchParams.delete('group');
                        url.searchParams.delete('phase');

                        if (isStatType) {
                            url.searchParams.set('view', this.section);
                            if (this.statGroup !== 'all') url.searchParams.set('group', this.statGroup);
                            if (this.statScope !== 'league') url.searchParams.set('phase', this.statScope);
                            url.hash = '';
                        } else if (this.section === 'calendario') {
                            if (!url.hash.startsWith('#calendario')) url.hash = 'calendario';
                        } else {
                            url.hash = '';
                        }

                        history.replaceState(null, '', url);
                    },
                }"
                x-effect="syncUrl()"
            >
                <x-ui.section-tabs :tabs="[
                    ['key' => 'tabla', 'label' => __('Tabla de posiciones'), 'icon' => 'table-cells'],
                    ['key' => 'calendario', 'label' => __('Calendario'), 'icon' => 'calendar-days'],
                    ['key' => \App\Enums\MatchEventType::Goal->value, 'label' => __(\App\Enums\MatchEventType::Goal->leaderboardTitle()), 'icon' => 'trophy'],
                    ['key' => \App\Enums\MatchEventType::Assist->value, 'label' => __(\App\Enums\MatchEventType::Assist->leaderboardTitle()), 'icon' => 'hand-raised'],
                    ['key' => \App\Enums\MatchEventType::YellowCard->value, 'label' => __(\App\Enums\MatchEventType::YellowCard->leaderboardTitle()), 'icon' => 'rectangle-stack'],
                    ['key' => \App\Enums\MatchEventType::RedCard->value, 'label' => __(\App\Enums\MatchEventType::RedCard->leaderboardTitle()), 'icon' => 'rectangle-stack'],
                ]" />

            <div
                x-show="section === 'calendario'"
                x-cloak
                class="mt-4 space-y-4"
                x-data="{
                    activeGroup: 0,
                    startRound: {{ \Illuminate\Support\Js::from($schedules->pluck('start_round_index')->values()) }},
                    currentRound: {{ \Illuminate\Support\Js::from($schedules->pluck('start_round_index')->values()) }},
                }"
            >
                <flux:heading size="lg">{{ __('Calendario') }}</flux:heading>

                @if ($schedules->isEmpty())
                    <x-ui.empty-state icon="calendar-days" :message="__('Todavía no se ha generado ningún calendario para esta fase.')" />
                @else
                    @if ($schedules->count() > 1)
                        <div class="inline-flex flex-wrap gap-1.5 rounded-xl border border-zinc-200 bg-zinc-100/70 p-1.5 dark:border-white/10 dark:bg-white/5">
                            @foreach ($schedules as $index => $item)
                                <button
                                    type="button"
                                    @click="activeGroup = {{ $index }}; currentRound[{{ $index }}] = startRound[{{ $index }}]"
                                    :class="activeGroup === {{ $index }} ? 'bg-white text-zinc-900 shadow-[0_0_0_1px_var(--color-accent)] dark:bg-white/10 dark:text-white' : 'text-zinc-600 hover:bg-white/60 dark:text-white/70 dark:hover:bg-white/10 dark:hover:text-white'"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium transition-all duration-150 hover:scale-105 active:scale-95"
                                >
                                    <flux:icon.squares-2x2 variant="micro" class="size-4 text-amber-400" />
                                    {{ $item['schedule']->group?->name ?? $category->name }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($schedules as $index => $item)
                        @php [$schedule, $rounds] = [$item['schedule'], $item['rounds']]; @endphp
                        @php $lastRoundIndex = max(count($rounds) - 1, 0); @endphp

                        <div
                            x-show="activeGroup === {{ $index }}"
                            @if ($schedules->count() > 1) x-cloak @endif
                            x-transition:enter="transition ease-out duration-300"
                            x-transition:enter-start="opacity-0 translate-y-2 scale-[0.99]"
                            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                            class="relative space-y-6 overflow-hidden rounded-3xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-7"
                        >
                            <div class="pointer-events-none absolute -top-32 left-1/2 h-56 w-[140%] -translate-x-1/2 bg-gradient-to-b from-green-500/15 via-cyan-500/5 to-transparent blur-2xl"></div>

                            <div class="relative flex flex-wrap items-center justify-between gap-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-amber-500/15 text-amber-400">
                                        <flux:icon.squares-2x2 variant="micro" class="size-5" />
                                    </div>
                                    <flux:heading size="sm" class="text-lg!">
                                        {{ $schedule->group?->name ?? $category->name }}
                                    </flux:heading>
                                </div>
                                <flux:badge size="sm" color="zinc">{{ __('FORMATO: :format', ['format' => mb_strtoupper($schedule->format->label())]) }}</flux:badge>
                            </div>

                            @if (count($rounds) === 0)
                                <x-ui.empty-state icon="calendar-days" :message="__('Esta tabla todavía no tiene partidos.')" />
                            @else
                                <div>
                                    <div class="relative mb-6 flex items-center justify-center gap-4 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="chevron-left"
                                            @click="currentRound[{{ $index }}] = Math.max(0, currentRound[{{ $index }}] - 1)"
                                            x-bind:disabled="currentRound[{{ $index }}] <= 0"
                                        />

                                        <div class="flex items-center gap-2">
                                            <flux:icon.calendar-days variant="micro" class="size-4 text-accent-content" />
                                            <flux:text class="w-28 text-center text-sm font-semibold text-zinc-700 dark:text-white/85" x-text="'{{ __('Jornada') }} ' + (currentRound[{{ $index }}] + 1) + ' {{ __('de') }} {{ count($rounds) }}'"></flux:text>
                                        </div>

                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="chevron-right"
                                            @click="currentRound[{{ $index }}] = Math.min({{ $lastRoundIndex }}, currentRound[{{ $index }}] + 1)"
                                            x-bind:disabled="currentRound[{{ $index }}] >= {{ $lastRoundIndex }}"
                                        />
                                    </div>

                                    @foreach ($rounds as $roundIdx => $round)
                                        <div
                                            x-show="currentRound[{{ $index }}] === {{ $roundIdx }}"
                                            x-cloak
                                            class="relative"
                                        >
                                            <div class="mb-3 text-center text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                                {{ __('Jornada :number', ['number' => $round['round_number']]) }}
                                                @if ($schedule->format === \App\Enums\ScheduleFormat::HomeAndAway)
                                                    — {{ $round['leg'] === 1 ? __('Primera vuelta') : __('Segunda vuelta') }}
                                                @endif
                                            </div>

                                            <div class="flex flex-wrap justify-center gap-4">
                                                @foreach ($round['matches'] as $match)
                                                    <x-ui.match-card :match="$match" />
                                                @endforeach

                                                @if ($round['resting_team'])
                                                    <x-ui.match-card :resting="$round['resting_team']->name" />
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>

            <div x-show="section === 'tabla'" x-cloak class="mt-4 space-y-4">
                <flux:heading size="lg">{{ __('Tabla de posiciones') }}</flux:heading>

                <div class="grid gap-4 {{ count($standings) > 1 ? 'lg:grid-cols-2' : '' }}">
                    @foreach ($standings as $item)
                        <x-ui.standings-table :label="$item['label']" :rows="$item['rows']" />
                    @endforeach
                </div>
            </div>

            @php
                $statGroupKeys = $statistics['groupOptions']->isNotEmpty()
                    ? ['all', ...$statistics['groupOptions']->pluck('id')->map(fn ($id) => (string) $id)->all()]
                    : ['all'];
            @endphp

            @foreach (\App\Enums\MatchEventType::cases() as $statType)
                <div x-show="section === '{{ $statType->value }}'" x-cloak class="mt-4 space-y-4">
                    <flux:heading size="lg">{{ __($statType->leaderboardTitle()) }}</flux:heading>

                    <div class="flex flex-wrap items-center gap-3">
                        @if ($statistics['groupOptions']->isNotEmpty())
                            <x-ui.section-tabs model="statGroup" :tabs="[
                                ['key' => 'all', 'label' => __('Todos los grupos')],
                                ...$statistics['groupOptions']->map(fn ($group) => [
                                    'key' => (string) $group->id,
                                    'label' => $group->name,
                                ])->values()->all(),
                            ]" />
                        @endif

                        <x-ui.section-tabs model="statScope" :tabs="[
                            ['key' => \App\Enums\StatisticsPhaseScope::League->value, 'label' => __(\App\Enums\StatisticsPhaseScope::League->label())],
                            ['key' => \App\Enums\StatisticsPhaseScope::All->value, 'label' => __(\App\Enums\StatisticsPhaseScope::All->label())],
                        ]" />
                    </div>

                    @foreach (\App\Enums\StatisticsPhaseScope::cases() as $statScopeCase)
                        @foreach ($statGroupKeys as $groupKey)
                            <div x-show="statScope === '{{ $statScopeCase->value }}' && statGroup === '{{ $groupKey }}'" x-cloak>
                                <x-ui.statistics-leaderboard
                                    :rows="$statistics['panels'][$statType->value][$statScopeCase->value][$groupKey]"
                                    :type="$statType"
                                    :show-group="$groupKey === 'all'"
                                />
                            </div>
                        @endforeach
                    @endforeach
                </div>
            @endforeach

            @if ($champion)
                <div class="mt-4">
                    @include('pages.phases._champion-card', ['team' => $champion])
                </div>
            @endif
            </div>

            <flux:separator variant="subtle" />
        @else
            <div id="cuadro" class="scroll-mt-24 space-y-6">
                <flux:heading size="lg">{{ __('Cuadro de eliminación') }}</flux:heading>

                @if (empty($bracketRounds))
                    <x-ui.empty-state icon="bolt" :message="__('Todavía no se ha generado el cuadro de esta fase.')" />
                @else
                    {{-- Mobile/tablet: rounds stacked top to bottom, one under the other. --}}
                    <div class="space-y-6 lg:hidden">
                        @foreach ($bracketRounds as $round)
                            <div class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400">
                                        <flux:icon.bolt variant="micro" class="size-4" />
                                    </div>
                                    <flux:heading size="sm" class="text-base!">{{ $round['label'] }}</flux:heading>
                                </div>

                                <div class="flex flex-wrap justify-center gap-4">
                                    @foreach ($round['matches'] as $cross)
                                        @foreach ($cross as $legMatch)
                                            <x-ui.match-card :match="$legMatch" />
                                        @endforeach
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="hidden overflow-x-auto pb-4 lg:block">
                        <div class="flex w-full items-stretch justify-center {{ $bracketSize['columnGap'] }} px-6 lg:px-10">
                            @foreach ($bracketColumns as $column)
                                <div class="flex {{ $bracketSize['column'] }} flex-col">
                                    <div class="mb-3 flex items-center justify-center gap-1.5 text-center text-xs font-semibold uppercase tracking-wider
                                        {{ $column['side'] === 'final' ? 'text-amber-400' : 'text-zinc-500 dark:text-white/50' }}">
                                        @if ($column['side'] === 'final')
                                            <flux:icon.trophy variant="micro" class="size-3.5" />
                                        @endif
                                        {{ $column['label'] }}
                                    </div>

                                    <div class="flex flex-1 flex-col {{ $column['side'] === 'final' ? 'justify-center' : 'justify-around' }}">
                                        @if ($column['side'] === 'final')
                                            @php $decisive = $column['matches']->first()->last(); @endphp
                                            <x-ui.bracket-match-card
                                                :match="$decisive"
                                                :allow-picker="false"
                                                :card-class="$bracketSize['card']"
                                                :row-class="$bracketSize['row']"
                                                :text-class="$bracketSize['text']"
                                            />
                                        @else
                                            @foreach ($column['matches']->chunk(2) as $pair)
                                                @if ($pair->count() === 2)
                                                    <div class="{{ $column['side'] === 'left' ? $bracketSize['pairWrapperLeft'] : $bracketSize['pairWrapperRight'] }}">
                                                        @foreach ($pair as $cross)
                                                            @php $decisive = $cross->last(); @endphp
                                                            <div class="{{ $column['side'] === 'left' ? $bracketSize['cardStubLeft'] : $bracketSize['cardStubRight'] }}">
                                                                <x-ui.bracket-match-card
                                                                    :match="$decisive"
                                                                    :allow-picker="false"
                                                                    :card-class="$bracketSize['card']"
                                                                    :row-class="$bracketSize['row']"
                                                                    :text-class="$bracketSize['text']"
                                                                />
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    @php $decisive = $pair->first()->last(); @endphp
                                                    <div class="{{ $column['side'] === 'left' ? $bracketSize['singleStubLeft'] : $bracketSize['singleStubRight'] }}">
                                                        <x-ui.bracket-match-card
                                                            :match="$decisive"
                                                            :allow-picker="false"
                                                            :card-class="$bracketSize['card']"
                                                            :row-class="$bracketSize['row']"
                                                            :text-class="$bracketSize['text']"
                                                        />
                                                    </div>
                                                @endif
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @include('pages.phases._champion-card', ['team' => $champion])
                @endif
            </div>

            <flux:separator variant="subtle" />
        @endif
    </div>
</x-layouts::public>
