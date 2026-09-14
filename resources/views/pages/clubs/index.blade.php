@php
    // $teams is grouped ['category_id' => ['group_id|null' => Collection<Team>]] --
    // organized by category (and group within it) on purpose: a club that
    // fields several categories/groups shows up once per section instead
    // of being buried in one flat club list. $teamsByClub is the same
    // underlying $allTeams collection grouped the other way, for the "por
    // club" view -- see ClubController::index().
@endphp

<x-layouts::app :title="__('Clubes')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Clubes')" :subtitle="match($view) {
            'club' => __('Cada club, con las categorías en las que tiene plantel.'),
            'jugadores' => __('Buscá un jugador ya registrado por nombre o documento, en cualquier club/categoría.'),
            default => __('Organizados por categoría -- un club con varios planteles aparece en cada una.'),
        }">
            <x-slot:actions>
                <flux:button :href="route('clubs.create')" variant="primary" icon="plus" wire:navigate>
                    {{ __('Nuevo club') }}
                </flux:button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.nav-tabs :tabs="[
            ['label' => __('Por categoría'), 'icon' => 'rectangle-stack', 'href' => route('clubs.index', ['view' => 'category']), 'active' => $view === 'category'],
            ['label' => __('Por club'), 'icon' => 'shield-check', 'href' => route('clubs.index', ['view' => 'club']), 'active' => $view === 'club'],
            ['label' => __('Jugadores'), 'icon' => 'magnifying-glass', 'href' => route('clubs.index', ['view' => 'jugadores']), 'active' => $view === 'jugadores'],
        ]" />

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @if ($view === 'jugadores')
            {{-- All of the organizer's players are preloaded and filtered
                 entirely client-side as they type -- same pattern (and same
                 reasoning: this whole catalog is small enough that a live
                 search endpoint would be overkill) as
                 x-ui.match-lineup-search's own player search. Every row is
                 rendered server-side up front; only its visibility toggles,
                 via a per-row precomputed lowercase "name + documento"
                 haystack checked against the shared `query`. --}}
            <div class="space-y-6" x-data="{ query: '' }">
                <div class="mx-auto w-full max-w-xl">
                    <flux:input
                        type="search"
                        x-model="query"
                        icon="magnifying-glass"
                        :placeholder="__('Nombre o documento del jugador...')"
                        autofocus
                    />
                </div>

                @if ($players->isEmpty())
                    <x-ui.empty-state icon="magnifying-glass" :message="__('Todavía no tenés jugadores registrados en ningún club.')" />
                @else
                    @php
                        $jsHaystacks = \Illuminate\Support\Js::from(
                            $players->map(fn ($player) => mb_strtolower($player->full_name.' '.$player->document_number))->values()
                        );
                    @endphp

                    <div x-show="query.trim() === ''" x-cloak>
                        <x-ui.empty-state icon="magnifying-glass" :message="__('Escribí un nombre o número de documento para buscar.')" />
                    </div>

                    <div x-show="query.trim() !== '' && ! {{ $jsHaystacks }}.some((h) => h.includes(query.trim().toLowerCase()))" x-cloak>
                        <x-ui.empty-state icon="magnifying-glass" :message="__('No se encontró ningún jugador con esa búsqueda.')" />
                    </div>

                    <div class="mx-auto w-full max-w-3xl space-y-3">
                        @foreach ($players as $player)
                            @php
                                $jsHaystack = \Illuminate\Support\Js::from(mb_strtolower($player->full_name.' '.$player->document_number));
                            @endphp

                            <a
                                href="{{ route('players.edit', $player) }}"
                                wire:navigate
                                x-show="query.trim() !== '' && {{ $jsHaystack }}.includes(query.trim().toLowerCase())"
                                x-cloak
                                class="hover-lift block overflow-hidden rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <flux:heading size="lg" class="truncate">{{ $player->full_name }}</flux:heading>
                                        @if ($player->document_number)
                                            <flux:text class="text-zinc-500 dark:text-white/50">{{ __('Documento') }}: {{ $player->document_number }}</flux:text>
                                        @endif
                                    </div>

                                    @unless ($player->is_active)
                                        <flux:badge size="sm" color="zinc">{{ __('Inactivo') }}</flux:badge>
                                    @endunless
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    @forelse ($player->allTeams() as $team)
                                        <flux:badge size="sm" color="cyan">{{ $team->club?->name ?? $team->name }} · {{ $team->category->name }}</flux:badge>
                                    @empty
                                        <flux:badge size="sm" color="zinc">{{ __('Sin plantel') }}</flux:badge>
                                    @endforelse
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @elseif ($clubCount === 0)
            <x-ui.empty-state icon="shield-check" :message="__('Todavía no has registrado ningún club.')">
                <x-slot:action>
                    <flux:button :href="route('clubs.create')" variant="primary" size="sm" icon="plus" wire:navigate>
                        {{ __('Crear club') }}
                    </flux:button>
                </x-slot:action>
            </x-ui.empty-state>
        @elseif ($categories->isEmpty())
            <x-ui.empty-state icon="rectangle-stack" :message="__('Crea primero una categoría para poder ubicar tus clubes en ella.')">
                <x-slot:action>
                    <flux:button :href="route('categories.create')" variant="primary" size="sm" icon="plus" wire:navigate>
                        {{ __('Crear categoría') }}
                    </flux:button>
                </x-slot:action>
            </x-ui.empty-state>
        @elseif ($view === 'club')
            <div class="space-y-3">
                @foreach ($clubs as $club)
                    @php
                        $clubTeams = $teamsByClub->get($club->id, collect());
                        $hasIncompleteInClub = $clubTeams->contains(fn ($team) => in_array($team->id, $incompleteTeamIds));
                        $teamsByCategory = $clubTeams
                            ->groupBy('category.name')
                            ->map(fn ($teams) => $teams->groupBy(fn ($team) => $team->group->name ?? __('Sin grupo')));
                    @endphp

                    <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel" x-data="{ open: false }">
                        <div class="flex w-full items-center justify-between gap-3 px-6 py-4">
                            <button type="button" @click="open = !open" class="flex min-w-0 flex-1 cursor-pointer items-center gap-3 text-left">
                                <flux:icon.shield-check variant="micro" class="size-5 shrink-0 text-zinc-400" />

                                <div class="flex min-w-0 flex-wrap items-center gap-2">
                                    <flux:heading size="lg" class="truncate">{{ $club->name }}</flux:heading>
                                    <flux:badge size="sm" color="zinc">{{ trans_choice(':count plantel|:count planteles', $clubTeams->count(), ['count' => $clubTeams->count()]) }}</flux:badge>

                                    @if ($hasIncompleteInClub)
                                        <flux:tooltip :content="__('Hay planteles con jugadores sin fecha de nacimiento')">
                                            <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0 text-amber-500" />
                                        </flux:tooltip>
                                    @endif
                                </div>
                            </button>

                            <div class="flex shrink-0 items-center gap-2">
                                <flux:button :href="route('clubs.show', $club)" variant="ghost" size="sm" icon="pencil" wire:navigate>
                                    {{ __('Editar club') }}
                                </flux:button>

                                <button type="button" @click="open = !open" class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg transition-colors hover:bg-zinc-100 dark:hover:bg-white/10">
                                    <flux:icon.chevron-down variant="micro" class="size-4 text-zinc-400 transition-transform duration-200" x-bind:class="open && 'rotate-180'" />
                                </button>
                            </div>
                        </div>

                        <div x-show="open" x-collapse.duration.200ms>
                            <div class="space-y-5 border-t border-zinc-200 px-7 py-5 dark:border-white/10">
                                @if ($clubTeams->isEmpty())
                                    <x-ui.empty-state icon="user-group" :message="__('Este club todavía no tiene planteles registrados.')">
                                        <x-slot:action>
                                            <flux:button :href="route('clubs.teams.create', $club)" variant="primary" size="sm" icon="plus" wire:navigate>
                                                {{ __('Nuevo plantel') }}
                                            </flux:button>
                                        </x-slot:action>
                                    </x-ui.empty-state>
                                @else
                                    @foreach ($teamsByCategory as $categoryName => $groupedTeams)
                                        <div class="space-y-2">
                                            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                                <flux:icon.rectangle-stack variant="micro" class="size-3.5" />
                                                {{ $categoryName }}
                                            </div>

                                            @foreach ($groupedTeams as $groupName => $groupTeams)
                                                <div>
                                                    @if ($groupedTeams->count() > 1)
                                                        <div class="mb-1.5 flex items-center gap-2 pl-1 text-xs text-zinc-400 dark:text-white/40">
                                                            <flux:icon.squares-2x2 variant="micro" class="size-3" />
                                                            {{ $groupName }}
                                                        </div>
                                                    @endif

                                                    <x-ui.clubs-table :teams="$groupTeams->sortBy('name')" :incomplete-team-ids="$incompleteTeamIds" />
                                                </div>
                                            @endforeach
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="space-y-3">
                @foreach ($categories as $category)
                    @php
                        $categoryTeams = $teams->get($category->id, collect());
                        $totalInCategory = $categoryTeams->flatten(1)->count();
                        $hasIncompleteInCategory = $categoryTeams->flatten(1)->contains(fn ($team) => in_array($team->id, $incompleteTeamIds));
                    @endphp

                    <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel" x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="flex w-full cursor-pointer items-center justify-between gap-3 px-6 py-4 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-white/5">
                            <div class="flex min-w-0 items-center gap-3">
                                <flux:icon.rectangle-stack variant="micro" class="size-5 shrink-0 text-zinc-400" />

                                <div class="flex min-w-0 flex-wrap items-center gap-2">
                                    <flux:heading size="lg" class="truncate">{{ $category->name }}</flux:heading>
                                    <flux:badge size="sm" color="zinc">{{ trans_choice(':count plantel|:count planteles', $totalInCategory, ['count' => $totalInCategory]) }}</flux:badge>

                                    @if ($hasIncompleteInCategory)
                                        <flux:tooltip :content="__('Hay planteles con jugadores sin fecha de nacimiento')">
                                            <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0 text-amber-500" />
                                        </flux:tooltip>
                                    @endif
                                </div>
                            </div>

                            <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 text-zinc-400 transition-transform duration-200" x-bind:class="open && 'rotate-180'" />
                        </button>

                        <div x-show="open" x-collapse.duration.200ms>
                            <div class="space-y-3 border-t border-zinc-200 px-7 py-5 dark:border-white/10">
                                @if ($totalInCategory === 0)
                                    <x-ui.empty-state icon="shield-check" :message="__('Todavía ningún club tiene plantel en esta categoría.')" />
                                @elseif ($category->uses_groups)
                                    @foreach ($category->groups as $group)
                                        @php
                                            $groupTeams = $categoryTeams->get($group->id, collect())->sortBy(fn ($team) => $team->club->name);
                                            $hasIncompleteInGroup = $groupTeams->contains(fn ($team) => in_array($team->id, $incompleteTeamIds));
                                        @endphp

                                        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-white/10" x-data="{ open: false }">
                                            <button type="button" @click="open = !open" class="flex w-full cursor-pointer items-center justify-between gap-2 px-6 py-5 text-left transition-colors hover:bg-zinc-100 dark:hover:bg-white/5">
                                                <div class="flex items-center gap-2.5">
                                                    <flux:icon.squares-2x2 variant="micro" class="size-4 text-zinc-400" />
                                                    <flux:heading size="lg">{{ $group->name }}</flux:heading>
                                                    <flux:badge size="sm" color="zinc">{{ trans_choice(':count plantel|:count planteles', $groupTeams->count(), ['count' => $groupTeams->count()]) }}</flux:badge>

                                                    @if ($hasIncompleteInGroup)
                                                        <flux:tooltip :content="__('Hay planteles con jugadores sin fecha de nacimiento')">
                                                            <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0 text-amber-500" />
                                                        </flux:tooltip>
                                                    @endif
                                                </div>

                                                <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 text-zinc-400 transition-transform duration-200" x-bind:class="open && 'rotate-180'" />
                                            </button>

                                            <div x-show="open" x-collapse.duration.200ms>
                                                <div class="border-t border-zinc-200 dark:border-white/10">
                                                    <x-ui.clubs-table :teams="$groupTeams" :incomplete-team-ids="$incompleteTeamIds" class="rounded-none! border-0!" />
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach

                                    @php
                                        $ungrouped = $categoryTeams->get(null, collect());
                                        $hasIncompleteInUngrouped = $ungrouped->contains(fn ($team) => in_array($team->id, $incompleteTeamIds));
                                    @endphp
                                    @if ($ungrouped->isNotEmpty())
                                        <div class="overflow-hidden rounded-xl border border-amber-500/30" x-data="{ open: false }">
                                            <button type="button" @click="open = !open" class="flex w-full cursor-pointer items-center justify-between gap-2 px-6 py-5 text-left transition-colors hover:bg-amber-500/5">
                                                <div class="flex items-center gap-2.5">
                                                    <flux:icon.exclamation-triangle variant="micro" class="size-4 text-amber-500" />
                                                    <flux:heading size="lg">{{ __('Sin grupo') }}</flux:heading>
                                                    <flux:badge size="sm" color="amber">{{ trans_choice(':count plantel|:count planteles', $ungrouped->count(), ['count' => $ungrouped->count()]) }}</flux:badge>

                                                    @if ($hasIncompleteInUngrouped)
                                                        <flux:tooltip :content="__('Hay planteles con jugadores sin fecha de nacimiento')">
                                                            <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0 text-amber-500" />
                                                        </flux:tooltip>
                                                    @endif
                                                </div>

                                                <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 text-zinc-400 transition-transform duration-200" x-bind:class="open && 'rotate-180'" />
                                            </button>

                                            <div x-show="open" x-collapse.duration.200ms>
                                                <div class="border-t border-amber-500/30">
                                                    <x-ui.clubs-table :teams="$ungrouped->sortBy(fn ($team) => $team->club->name)" :incomplete-team-ids="$incompleteTeamIds" amber class="rounded-none! border-0!" />
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @else
                                    <x-ui.clubs-table :teams="$categoryTeams->get(null, collect())->sortBy(fn ($team) => $team->club->name)" :incomplete-team-ids="$incompleteTeamIds" />
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts::app>
