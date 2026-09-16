@php
    $finished = $match->status === \App\Enums\MatchStatus::Finished;
    $wentToExtraTime = $match->home_extra_time_score !== null && $match->away_extra_time_score !== null;
    $wentToPenalties = $match->home_penalty_score !== null && $match->away_penalty_score !== null;
    $homeRedCards = $match->home_team_id ? $match->redCardCountForTeam($match->home_team_id) : 0;
    $awayRedCards = $match->away_team_id ? $match->redCardCountForTeam($match->away_team_id) : 0;
    $title = ($match->homeTeam?->name ?? __('Por definir')).' vs '.($match->awayTeam?->name ?? __('Por definir'));
@endphp

<x-layouts::public :title="$title">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$title">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => $tournament->name, 'href' => route('public.tournaments.show', $tournament)],
                    ['label' => $match->category->name, 'href' => route('public.tournaments.categories.show', [$tournament, $match->category])],
                    ['label' => $match->competitionPhase->name, 'href' => route('public.tournaments.phases.show', [$tournament, $match->competitionPhase])],
                    ['label' => __('Partido')],
                ]" />
            </x-slot:breadcrumbs>

            <div class="mt-1 flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="$match->status->color()">{{ mb_strtoupper($match->status->label()) }}</flux:badge>

                @if ($match->is_walkover)
                    <flux:badge size="sm" color="red" icon="no-symbol">{{ mb_strtoupper(__('Perdido por W')) }}</flux:badge>
                @endif

                @if ($match->scheduled_at)
                    <flux:badge size="sm" color="zinc" icon="calendar-days">{{ $match->scheduled_at->format('d/m/Y H:i') }}</flux:badge>
                @endif

                @if ($match->referee)
                    <flux:badge size="sm" color="zinc" icon="flag">{{ $match->referee->full_name }}</flux:badge>
                @endif
            </div>
        </x-ui.page-header>

        <flux:separator variant="subtle" />

        @if ($finished && $match->hasGoalMismatch())
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Los goles registrados como eventos no coinciden con el marcador de este partido.')" />
        @endif

        @if ($match->is_walkover)
            <flux:callout variant="danger" icon="no-symbol" :heading="$match->expulsionLockMessage()" />
        @endif

        {{-- Scoreboard --}}
        <div class="rounded-3xl border border-zinc-200 bg-white p-6 dark:border-white/10 glass-panel sm:p-8">
            <div class="flex items-center justify-between gap-4">
                <div class="min-w-0 flex-1 text-right">
                    @if ($match->homeTeam)
                        <flux:link :href="route('public.tournaments.teams.show', [$tournament, $match->homeTeam])" wire:navigate class="text-lg font-semibold">
                            {{ $match->homeTeam->name }}
                        </flux:link>
                    @else
                        <span class="text-lg font-semibold text-zinc-400">{{ __('Por definir') }}</span>
                    @endif

                    @if ($homeRedCards > 0)
                        <div class="mt-1 flex items-center justify-end gap-0.5">
                            @for ($i = 0; $i < $homeRedCards; $i++)
                                <x-dynamic-component :component="\App\Enums\MatchEventType::RedCard->icon()" class="size-3.5 shrink-0 text-red-500" />
                            @endfor
                        </div>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-3 rounded-2xl bg-zinc-100 px-5 py-3 dark:bg-white/10">
                    <span class="font-display text-3xl font-bold tabular-nums">{{ $match->home_score ?? '–' }}</span>
                    <span class="text-zinc-400 dark:text-white/30">-</span>
                    <span class="font-display text-3xl font-bold tabular-nums">{{ $match->away_score ?? '–' }}</span>
                </div>

                <div class="min-w-0 flex-1 text-left">
                    @if ($match->awayTeam)
                        <flux:link :href="route('public.tournaments.teams.show', [$tournament, $match->awayTeam])" wire:navigate class="text-lg font-semibold">
                            {{ $match->awayTeam->name }}
                        </flux:link>
                    @else
                        <span class="text-lg font-semibold text-zinc-400">{{ __('Por definir') }}</span>
                    @endif

                    @if ($awayRedCards > 0)
                        <div class="mt-1 flex items-center gap-0.5">
                            @for ($i = 0; $i < $awayRedCards; $i++)
                                <x-dynamic-component :component="\App\Enums\MatchEventType::RedCard->icon()" class="size-3.5 shrink-0 text-red-500" />
                            @endfor
                        </div>
                    @endif
                </div>
            </div>

            @if ($wentToExtraTime || $wentToPenalties || $match->firstLeg)
                <div class="mt-4 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-center text-xs text-zinc-500 dark:text-white/50">
                    @if ($match->firstLeg)
                        <flux:link :href="route('public.tournaments.matches.show', [$tournament, $match->firstLeg])" wire:navigate>
                            {{ __('Ida :home-:away', ['home' => $match->firstLeg->home_score ?? '–', 'away' => $match->firstLeg->away_score ?? '–']) }}
                        </flux:link>
                    @endif

                    @if ($wentToExtraTime)
                        <span>{{ __('Incluye prórroga') }}</span>
                    @endif

                    @if ($wentToPenalties)
                        <span>{{ __('Penales: :home-:away', ['home' => $match->home_penalty_score, 'away' => $match->away_penalty_score]) }}</span>
                    @endif
                </div>
            @endif
        </div>

        {{-- Unavailable players/coaches for this match --}}
        @if ($homeUnavailable->isNotEmpty() || $awayUnavailable->isNotEmpty())
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Jugadores no disponibles') }}</flux:heading>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        @foreach ($homeUnavailable as $sanction)
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 dark:border-white/10 glass-panel">
                                <span class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $sanction->subjectLabel() }}</span>
                                <flux:badge size="sm" color="red">{{ $sanction->stateLabelForMatch($match->id) }}</flux:badge>
                            </div>
                        @endforeach
                    </div>

                    <div class="space-y-2">
                        @foreach ($awayUnavailable as $sanction)
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 dark:border-white/10 glass-panel">
                                <span class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $sanction->subjectLabel() }}</span>
                                <flux:badge size="sm" color="red">{{ $sanction->stateLabelForMatch($match->id) }}</flux:badge>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <flux:separator variant="subtle" />
        @endif

        {{-- Match events --}}
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Eventos del partido') }}</flux:heading>

            @if ($homeEventGroups->isEmpty() && $awayEventGroups->isEmpty())
                <x-ui.empty-state icon="flag" :message="__('Todavía no se registraron eventos (goles, asistencias, tarjetas) para este partido.')" />
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
                        <div class="border-b border-zinc-100 bg-zinc-50/60 px-4 py-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:border-white/5 dark:bg-white/[0.03] dark:text-white/50">
                            {{ $match->homeTeam?->name ?? __('Por definir') }}
                        </div>

                        @if ($homeEventGroups->isEmpty())
                            <div class="px-4 py-3 text-sm text-zinc-400 dark:text-white/40">{{ __('Sin eventos registrados.') }}</div>
                        @else
                            <div class="divide-y divide-zinc-100 dark:divide-white/5">
                                @foreach ($homeEventGroups as $events)
                                    <x-ui.public-match-event-row :events="$events" />
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
                        <div class="border-b border-zinc-100 bg-zinc-50/60 px-4 py-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:border-white/5 dark:bg-white/[0.03] dark:text-white/50">
                            {{ $match->awayTeam?->name ?? __('Por definir') }}
                        </div>

                        @if ($awayEventGroups->isEmpty())
                            <div class="px-4 py-3 text-sm text-zinc-400 dark:text-white/40">{{ __('Sin eventos registrados.') }}</div>
                        @else
                            <div class="divide-y divide-zinc-100 dark:divide-white/5">
                                @foreach ($awayEventGroups as $events)
                                    <x-ui.public-match-event-row :events="$events" />
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- Sanctions originated here --}}
        @if ($originatedSanctions->isNotEmpty())
            <flux:separator variant="subtle" />

            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Sanciones originadas en este partido') }}</flux:heading>

                <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                    @foreach ($originatedSanctions as $sanction)
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $sanction->subjectLabel() }}</div>
                                <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-white/50">{{ $sanction->team->name }}</div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <flux:badge size="sm" :color="$sanction->type->color()">{{ $sanction->type->label() }}</flux:badge>
                                <flux:badge size="sm" :color="$sanction->status->color()">{{ $sanction->stateLabel() }}</flux:badge>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts::public>
