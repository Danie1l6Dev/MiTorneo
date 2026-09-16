<x-layouts::public :title="$tournament->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$tournament->name" :subtitle="$tournament->description">
            <x-slot:actions>
                <flux:button :href="route('public.tournaments.sanctions.index', $tournament)" variant="ghost" icon="shield-exclamation" wire:navigate>
                    {{ __('Sanciones') }}
                </flux:button>
            </x-slot:actions>

            <div class="mt-1 flex items-center gap-2">
                <flux:badge size="sm" :color="$tournament->status->color()">{{ $tournament->status->label() }}</flux:badge>

                @if ($tournament->season)
                    <flux:text class="text-sm text-zinc-500">{{ $tournament->season }}</flux:text>
                @endif
            </div>

            @php
                $displayedCategories = $isLegacy ? $tournament->categories : $tournament->globalCategories;
                $categoriesCount = $isLegacy ? $tournament->categories_count : $tournament->global_categories_count;
                $teamsCount = $isLegacy ? $tournament->teams_count : $tournament->global_teams_count;
            @endphp

            <div class="mt-4 flex flex-wrap items-center gap-2.5">
                <x-ui.stat-pill icon="rectangle-group" :value="$categoriesCount" :label="__('categorías')" color="cyan" />
                <x-ui.stat-pill icon="user-group" :value="$teamsCount" :label="__('equipos')" color="amber" />
                <x-ui.stat-pill icon="calendar-days" :value="$tournament->matches_count" :label="__('partidos')" color="green" />
            </div>
        </x-ui.page-header>

        <flux:separator variant="subtle" />

        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Categorías') }}</flux:heading>

            @if ($displayedCategories->isEmpty())
                <x-ui.empty-state icon="rectangle-group" :message="__('Este torneo todavía no tiene categorías publicadas.')" />
            @else
                <div class="flex flex-wrap justify-center gap-4">
                    @foreach ($displayedCategories->sortBy('order') as $category)
                        <x-ui.entity-card
                            :href="route('public.tournaments.categories.show', [$tournament, $category])"
                            :title="$category->name"
                            icon="rectangle-group"
                            color="cyan"
                            :stats="[
                                trans_choice(':count equipo|:count equipos', $isLegacy ? $category->teams_count : ($globalTeamCounts[$category->id] ?? 0), ['count' => $isLegacy ? $category->teams_count : ($globalTeamCounts[$category->id] ?? 0)]),
                                $category->uses_groups ? trans_choice(':count grupo|:count grupos', $category->groups_count, ['count' => $category->groups_count]) : __('Sin grupos'),
                            ]"
                        >
                            <x-slot:badges>
                                <flux:badge size="sm" :color="$category->status->color()">{{ $category->status->label() }}</flux:badge>
                            </x-slot:badges>
                        </x-ui.entity-card>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::public>
