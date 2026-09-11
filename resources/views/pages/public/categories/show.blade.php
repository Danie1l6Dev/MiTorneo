<x-layouts::public :title="$category->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$category->name" :subtitle="$category->description">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => $tournament->name, 'href' => route('public.tournaments.show', $tournament)],
                    ['label' => $category->name],
                ]" />
            </x-slot:breadcrumbs>

            <div class="mt-1 flex items-center gap-2">
                <flux:badge size="sm" :color="$category->status->color()">{{ $category->status->label() }}</flux:badge>

                @if ($category->uses_groups)
                    <flux:badge size="sm" color="zinc">{{ __('Usa grupos') }}</flux:badge>
                @endif
            </div>
        </x-ui.page-header>

        <flux:separator variant="subtle" />

        @if ($category->uses_groups)
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Grupos') }}</flux:heading>

                @if ($category->groups->isEmpty())
                    <x-ui.empty-state icon="squares-2x2" :message="__('Todavía no hay grupos definidos.')" />
                @else
                    @php $teamsByGroup = $category->teams->groupBy('group_id'); @endphp

                    <div class="space-y-5">
                        @foreach ($category->groups->sortBy('order') as $group)
                            @php $groupTeams = $teamsByGroup->get($group->id, collect()); @endphp

                            <div>
                                <div class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                    <flux:icon.squares-2x2 variant="micro" class="size-3.5" />
                                    {{ $group->name }}
                                </div>

                                @if ($groupTeams->isEmpty())
                                    <flux:text class="text-sm text-zinc-400 dark:text-white/40">{{ __('Todavía no tiene equipos.') }}</flux:text>
                                @else
                                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                                        @foreach ($groupTeams->sortBy('name') as $team)
                                            <x-ui.team-chip :team="$team" />
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <flux:separator variant="subtle" />
        @else
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Equipos') }}</flux:heading>

                @if ($category->teams->isEmpty())
                    <x-ui.empty-state icon="user-group" :message="__('Todavía no hay equipos registrados.')" />
                @else
                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                        @foreach ($category->teams->sortBy('name') as $team)
                            <x-ui.team-chip :team="$team" />
                        @endforeach
                    </div>
                @endif
            </div>

            <flux:separator variant="subtle" />
        @endif

        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Fases') }}</flux:heading>

            @if ($category->competitionPhases->isEmpty())
                <x-ui.empty-state icon="calendar-days" :message="__('Todavía no hay fases publicadas para esta categoría.')" />
            @else
                <div class="flex flex-wrap justify-center gap-4">
                    @foreach ($category->competitionPhases->sortBy('order') as $phase)
                        <x-ui.entity-card
                            :href="route('public.tournaments.phases.show', [$tournament, $phase])"
                            :title="$phase->name"
                            :icon="$phase->type->icon()"
                            :color="$phase->type->color()"
                            :stats="[trans_choice(':count partido|:count partidos', $phase->matches_count, ['count' => $phase->matches_count])]"
                            :cta="__('Ver fase')"
                        >
                            <x-slot:badges>
                                <flux:badge size="sm" :color="$phase->type->color()">{{ $phase->type->label() }}</flux:badge>
                            </x-slot:badges>
                        </x-ui.entity-card>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::public>
