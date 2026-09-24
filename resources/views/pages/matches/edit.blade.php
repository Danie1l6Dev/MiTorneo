<x-layouts::app :title="__('Editar partido')">
    <div
        class="mx-auto w-full max-w-7xl space-y-8 animate-fade-in-up"
        x-data="{
            // Repopulated from $oldQueuedEvents (built in
            // TournamentMatchController::edit() from old('events')) when
            // this page is the redirect-back from a failed batch submit --
            // e.g. the assist-vs-goal rule -- so whatever was already
            // queued survives instead of the user having to re-click every
            // icon. Empty on a normal page load, same as before.
            pending: {{ \Illuminate\Support\Js::from($oldQueuedEvents ?? []) }},
            nextId: {{ count($oldQueuedEvents ?? []) + 1 }},
            // Player and coach ids share the same numeric range across
            // their two tables, so every lookup below is keyed by
            // (subjectType, subjectId) together, never subjectId alone.
            yellowCounts: {
                player: {{ \Illuminate\Support\Js::from($playerYellowCounts ?? []) }},
                coach: {{ \Illuminate\Support\Js::from($coachYellowCounts ?? []) }},
            },
            redSubjectIds: {
                player: {{ \Illuminate\Support\Js::from($redPlayerIds ?? []) }},
                coach: {{ \Illuminate\Support\Js::from($redCoachIds ?? []) }},
            },
            countFor(subjectType, subjectId) {
                const saved = this.yellowCounts[subjectType][subjectId] ?? 0;
                const queued = this.pending
                    .filter((e) => e.subjectType === subjectType && e.subjectId === subjectId && e.type === 'yellow_card')
                    .reduce((sum, e) => sum + e.count, 0);
                return saved + queued;
            },
            isExpelled(subjectType, subjectId) {
                if (this.redSubjectIds[subjectType].includes(subjectId)) return true;
                if (this.pending.some((e) => e.subjectType === subjectType && e.subjectId === subjectId && e.type === 'red_card')) return true;
                return this.countFor(subjectType, subjectId) >= 2;
            },
            // Pairs each queued item with its real index in `pending` (not
            // the filtered list's own index) so a roster panel's remove
            // button always splices the right one, even though it only
            // ever renders its own team's slice of the queue.
            pendingForTeam(teamId) {
                return this.pending
                    .map((item, index) => ({ item, index }))
                    .filter((entry) => entry.item.teamId === teamId);
            },
            // A repeat of the same type+subject accumulates onto the
            // existing line (count++) instead of adding a new one -- e.g.
            // clicking Gol twice for the same player shows one 2x line,
            // not two identical rows.
            queue(type, subjectType, subjectId, teamId, label, note = null) {
                const existing = this.pending.find((e) => e.type === type && e.subjectType === subjectType && e.subjectId === subjectId);

                if (existing) {
                    existing.count++;
                    if (note) existing.note = note;
                    return;
                }

                this.pending.push({ uid: this.nextId++, type, subjectType, subjectId, teamId, label, note, count: 1 });
            },
            addGoal(playerId, teamId, label) {
                this.queue('goal', 'player', playerId, teamId, label);
            },
            addAssist(playerId, teamId, label) {
                this.queue('assist', 'player', playerId, teamId, label);
            },
            // subjectType is 'player' or 'coach' -- a coach (the team's
            // DT) can be shown cards exactly like a player, just never a
            // goal or assist (there are no addGoal/addAssist calls for
            // 'coach' anywhere, and the backend rejects it too).
            addRed(subjectType, subjectId, teamId, label) {
                if (this.isExpelled(subjectType, subjectId)) return;
                this.queue('red_card', subjectType, subjectId, teamId, label);
            },
            addYellow(subjectType, subjectId, teamId, label) {
                if (this.isExpelled(subjectType, subjectId)) return;
                const count = this.countFor(subjectType, subjectId);
                this.queue('yellow_card', subjectType, subjectId, teamId, label, count === 1 ? '{{ __('Expulsión') }}' : null);
                if (count === 1) {
                    this.queue('red_card', subjectType, subjectId, teamId, label, '{{ __('Por 2ª amarilla') }}');
                }
            },
            // Undoes one occurrence at a time -- decrements an
            // accumulated line's count, only removing it once it hits 0.
            remove(index) {
                const item = this.pending[index];

                if (item.count > 1) {
                    item.count--;
                } else {
                    this.pending.splice(index, 1);
                }
            },
            totalPendingCount() {
                return this.pending.reduce((sum, item) => sum + item.count, 0);
            },
            // Lets the goles-vs-marcador callouts below react live as
            // goals are queued, without waiting for the batch save --
            // they add this on top of the already-saved DB count.
            queuedGoalCount(teamId) {
                return this.pending
                    .filter((e) => e.type === 'goal' && e.teamId === teamId)
                    .reduce((sum, e) => sum + e.count, 0);
            },
            // Expands accumulated lines back into one entry per actual
            // event, so the batch submit still creates exactly as many
            // match_events rows as were queued.
            flatEvents() {
                return this.pending.flatMap((item) => Array.from(
                    { length: item.count },
                    () => ({ type: item.type, subjectType: item.subjectType, subjectId: item.subjectId })
                ));
            },
        }"
    >
        <x-ui.page-header :title="__('Editar partido')" :subtitle="$match->competitionPhase->name">
            {{-- Registering a result no longer navigates away from this
                 page (see MatchResultController) -- this is the explicit
                 way back, landing on the same tab/group this match's own
                 entry lives under (TournamentMatch::calendarHash()). --}}
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <flux:button
                    :href="route('phases.show', $match->competitionPhase).$match->calendarHash()"
                    variant="ghost"
                    size="sm"
                    icon="chevron-left"
                    wire:navigate
                >
                    {{ __('Volver al calendario') }}
                </flux:button>

                {{-- Full single-match report (rosters, statistics, events,
                     sanctions) -- see MatchResultsPdfController::exportMatch(). --}}
                <x-ui.pdf-export-button
                    :href="route('matches.pdf', $match)"
                    :filename="'resultado-partido-'.str(collect([$match->homeTeam?->name, $match->awayTeam?->name])->filter()->implode(' vs ') ?: (string) $match->id)->slug().'.pdf'"
                    variant="ghost"
                    size="sm"
                    icon="arrow-down-tray"
                >
                    {{ __('Exportar PDF') }}
                </x-ui.pdf-export-button>
            </div>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @php
            $locked = $match->isLockedByExpulsion();
            $pending = $match->home_team_id === null || $match->away_team_id === null;
            $homeInitials = $match->homeTeam ? \Illuminate\Support\Str::substr($match->homeTeam->short_name ?: $match->homeTeam->name, 0, 2) : '?';
            $awayInitials = $match->awayTeam ? \Illuminate\Support\Str::substr($match->awayTeam->short_name ?: $match->awayTeam->name, 0, 2) : '?';
            $isDecisiveLeg = $match->isDecisiveKnockoutLeg();

            // Players/coach still serving a sanction from an earlier match
            // don't get quick-add buttons for THIS match -- they're pulled
            // out of the actionable roster and shown in
            // x-ui.match-unavailable-players instead (see
            // TournamentMatchController::edit()'s unavailableSanctions()).
            $homeUnavailablePlayerIds = $homeUnavailableSanctions->pluck('player_id')->filter();
            $awayUnavailablePlayerIds = $awayUnavailableSanctions->pluck('player_id')->filter();
            $homeCoachUnavailable = $match->homeTeam?->coach && $homeUnavailableSanctions->contains('coach_id', $match->homeTeam->coach->id);
            $awayCoachUnavailable = $match->awayTeam?->coach && $awayUnavailableSanctions->contains('coach_id', $match->awayTeam->coach->id);

            // The roster panels automatically show every player eligible
            // for each side (see TournamentMatchController::edit() --
            // Team::clubPlayersEligibleForLineup()): the team's own roster,
            // plus any play-up-eligible player from a younger category of
            // the same club. A suspended player is pulled from this list
            // the same way as before: they still shouldn't get quick-add
            // buttons.
            $homeAvailablePlayers = $homeEligiblePlayers->whereNotIn('id', $homeUnavailablePlayerIds)->values();
            $awayAvailablePlayers = $awayEligiblePlayers->whereNotIn('id', $awayUnavailablePlayerIds)->values();

            $resultErrorKeys = ['home_score', 'away_score', 'home_extra_time_score', 'away_extra_time_score', 'home_penalty_score', 'away_penalty_score'];
            $resultErrorMessage = collect($resultErrorKeys)->map(fn ($key) => $errors->first($key))->first(fn ($message) => $message !== '');

            // Errors from the "Guardar eventos" batch submit -- Laravel flattens
            // array-rule failures to keys like "events.2.player_id", so this
            // just grabs the first one under the "events" umbrella, whichever
            // roster panel it came from.
            $batchErrorKey = collect($errors->keys())->first(fn ($key) => $key === 'events' || str_starts_with($key, 'events.'));
            $quickAddErrorMessage = $batchErrorKey ? $errors->first($batchErrorKey) : null;
        @endphp

        @if ($quickAddErrorMessage)
            <flux:callout variant="danger" icon="exclamation-circle" :heading="$quickAddErrorMessage" />
        @endif

        @if ($locked)
            <flux:callout variant="danger" icon="no-symbol" :heading="__('Partido bloqueado: perdido por W')">
                {{ __(':team fue expulsado de esta categoría, así que este partido quedó 0-3 automáticamente y ya no se puede modificar. Para volver a editarlo, primero hay que revertir la expulsión.', ['team' => $match->walkoverTeam?->name ?? __('El equipo')]) }}
            </flux:callout>
        @endif

        {{-- The score card stays centered and alone while either team is
             unknown -- there's no roster to quick-add events for yet. Once
             both are set, the quick-add rosters flank it on desktop and
             stack below it on narrower screens (score stays the primary,
             first action either way).

             Everything below (both rosters, the pending-events tray, and its
             "Guardar eventos" form) shares this one Alpine scope: every
             player-row button just pushes into `pending` -- nothing is saved
             until that form's single submit, so registering several events
             in a row no longer reloads the page each time. A yellow-card
             click is checked against both already-saved counts (yellowCounts/
             redPlayerIds, seeded from the DB) and this session's own queued
             clicks -- the second yellow for the same player auto-queues the
             red that goes with it, instead of needing a separate button. --}}
        <div class="space-y-6">
        <div @class([
            // Same single centered column a walkover uses -- both hide the
            // side roster panels, so the 3-column grid below would leave
            // the score card stranded in its leftmost track (grid
            // auto-placement doesn't skip empty explicit tracks just
            // because the item's flanking columns have no content).
            'mx-auto grid max-w-2xl gap-4' => $pending || $locked,
            'grid gap-4 lg:grid-cols-[minmax(0,380px)_minmax(0,480px)_minmax(0,380px)] lg:items-start lg:justify-center' => ! $pending && ! $locked,
        ])>
            @unless ($pending || $locked)
                <div class="space-y-4 lg:order-1">
                    <x-ui.match-ineligible-players :players="$homeIneligiblePlayers" :team="$match->homeTeam" />
                    <x-ui.match-roster-panel :team="$match->homeTeam" :players="$homeAvailablePlayers" :hide-coach="$homeCoachUnavailable" />
                    <x-ui.match-pending-tray :team-id="$match->home_team_id" />
                    <x-ui.match-unavailable-players :sanctions="$homeUnavailableSanctions" :match-id="$match->id" />
                </div>
            @endunless

            <div class="lg:order-2">
                <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
                    <div class="flex items-center justify-center border-b border-zinc-200 px-4 py-2.5 dark:border-white/10">
                        @if ($pending)
                            <flux:badge size="sm" color="zinc">{{ mb_strtoupper(__('Por definir')) }}</flux:badge>
                        @else
                            <flux:badge size="sm" :color="$match->status->color()">{{ mb_strtoupper($match->status->label()) }}</flux:badge>
                        @endif
                    </div>

                    <div class="grid grid-cols-3 items-center gap-3 px-4 py-8 sm:px-8">
                        <div class="text-center">
                            <div class="mx-auto mb-2 flex size-14 items-center justify-center rounded-full bg-accent-content/15 text-lg font-bold uppercase text-accent-content sm:size-16">
                                {{ $homeInitials }}
                            </div>
                            <flux:heading size="sm" class="truncate">{{ $match->homeTeam?->name ?? __('Por definir') }}</flux:heading>
                        </div>

                        <div class="text-center">
                            <div class="flex items-center justify-center gap-2 sm:gap-4">
                                <span class="font-display text-5xl font-bold tabular-nums text-zinc-900 dark:text-white sm:text-6xl">{{ $match->home_score ?? '–' }}</span>
                                <span class="text-2xl font-light text-zinc-300 dark:text-white/25">:</span>
                                <span class="font-display text-5xl font-bold tabular-nums text-zinc-900 dark:text-white sm:text-6xl">{{ $match->away_score ?? '–' }}</span>
                            </div>

                            @if ($match->home_extra_time_score !== null && $match->away_extra_time_score !== null)
                                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-white/50">
                                    {{ __('Prórroga :home-:away', ['home' => $match->home_extra_time_score, 'away' => $match->away_extra_time_score]) }}
                                </flux:text>
                            @endif

                            @if ($match->home_penalty_score !== null && $match->away_penalty_score !== null)
                                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-white/50">
                                    {{ __('Penales :home-:away', ['home' => $match->home_penalty_score, 'away' => $match->away_penalty_score]) }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="text-center">
                            <div class="mx-auto mb-2 flex size-14 items-center justify-center rounded-full bg-accent-content/15 text-lg font-bold uppercase text-accent-content sm:size-16">
                                {{ $awayInitials }}
                            </div>
                            <flux:heading size="sm" class="truncate">{{ $match->awayTeam?->name ?? __('Por definir') }}</flux:heading>
                        </div>
                    </div>

                    @if ($locked)
                        <div class="flex flex-col items-center gap-2 border-t border-zinc-200 px-4 py-6 text-center dark:border-white/10 sm:px-8">
                            <div class="flex size-10 items-center justify-center rounded-full bg-red-500/15 text-red-500">
                                <flux:icon.no-symbol variant="mini" class="size-5" />
                            </div>
                            <flux:heading size="sm">{{ __('Perdido por W') }}</flux:heading>
                            <flux:text class="max-w-sm text-zinc-500 dark:text-white/60">
                                {{ __('Este resultado quedó fijo por la expulsión de :team. Hay que revertir la expulsión para poder editarlo.', ['team' => $match->walkoverTeam?->name ?? __('el equipo')]) }}
                            </flux:text>
                        </div>
                    @elseif ($pending)
                        <div class="border-t border-zinc-200 px-4 py-6 dark:border-white/10 sm:px-8">
                            <flux:callout
                                variant="secondary"
                                icon="clock"
                                :heading="__('Todavía no se conocen los dos equipos de este partido.')"
                                :text="__('Se completará automáticamente en cuanto termine el partido de la ronda anterior que define a su clasificado.')"
                            />
                        </div>
                    @else
                        <form
                            method="POST"
                            action="{{ route('matches.result.update', $match) }}"
                            class="space-y-5 border-t border-zinc-200 px-4 py-6 dark:border-white/10 sm:px-8"
                            x-data="{
                                homeScore: '{{ old('home_score', $match->home_score ?? '') }}',
                                awayScore: '{{ old('away_score', $match->away_score ?? '') }}',
                                // For the decisive leg of a two-legged cross, what matters is
                                // the AGGREGATE (this leg's score plus the first leg's, mapped
                                // onto this leg's sides -- sides swap between legs) being level,
                                // not this leg's own score alone. Null/0 for every other match.
                                firstLegHomeCarry: {{ $match->first_leg_match_id !== null && $match->firstLeg->away_score !== null ? $match->firstLeg->away_score : 'null' }},
                                firstLegAwayCarry: {{ $match->first_leg_match_id !== null && $match->firstLeg->home_score !== null ? $match->firstLeg->home_score : 'null' }},
                                wentToExtraTime: {{ \Illuminate\Support\Js::from(old('home_extra_time_score', $match->home_extra_time_score) !== null) }},
                                homeExtraTime: '{{ old('home_extra_time_score', $match->home_extra_time_score ?? '') }}',
                                awayExtraTime: '{{ old('away_extra_time_score', $match->away_extra_time_score ?? '') }}',
                                wentToPenalties: {{ \Illuminate\Support\Js::from(old('home_penalty_score', $match->home_penalty_score) !== null) }},
                                get regulationIsDraw() {
                                    if (this.homeScore === '' || this.awayScore === '') return false;
                                    const home = Number(this.homeScore) + (this.firstLegHomeCarry ?? 0);
                                    const away = Number(this.awayScore) + (this.firstLegAwayCarry ?? 0);
                                    return home === away;
                                },
                                get extraTimeIsDraw() {
                                    return this.homeExtraTime !== '' && this.awayExtraTime !== '' && Number(this.homeExtraTime) === Number(this.awayExtraTime);
                                },
                                get canGoToPenalties() {
                                    // Penalties don't always follow extra time -- some
                                    // competitions go straight to a shoot-out on a
                                    // regular-time draw. So it's available either way,
                                    // as long as the score at that point is level.
                                    return this.wentToExtraTime ? this.extraTimeIsDraw : this.regulationIsDraw;
                                },
                            }"
                            x-effect="
                                if (! regulationIsDraw) wentToExtraTime = false;
                                if (! canGoToPenalties) wentToPenalties = false;
                            "
                        >
                            @csrf
                            @method('PATCH')

                            {{-- Whatever is still queued in the rosters ("Por guardar")
                                 goes along with the result and is saved with it, under
                                 the same rules as the "Guardar eventos" button (see
                                 MatchResultRequest). Same one-field-name-per-row
                                 emission as that form below. --}}
                            <template x-for="(event, index) in flatEvents()" :key="'r' + index">
                                <span>
                                    <input type="hidden" :name="`events[${index}][type]`" :value="event.type">
                                    <input type="hidden" :name="`events[${index}][${event.subjectType === 'coach' ? 'coach_id' : 'player_id'}]`" :value="event.subjectId">
                                </span>
                            </template>

                            <div class="flex flex-nowrap items-end justify-center gap-4">
                                <flux:input
                                    name="home_score"
                                    type="number"
                                    min="0"
                                    label="{{ __('Local') }}"
                                    x-model="homeScore"
                                    error:message=""
                                    class="w-20"
                                />

                                <div class="pb-2.5 text-lg text-zinc-400 dark:text-white/30">&ndash;</div>

                                <flux:input
                                    name="away_score"
                                    type="number"
                                    min="0"
                                    label="{{ __('Visitante') }}"
                                    x-model="awayScore"
                                    error:message=""
                                    class="w-20"
                                />
                            </div>

                            @if ($match->first_leg_match_id !== null)
                                <flux:callout variant="secondary" icon="information-circle" class="mx-auto max-w-sm"
                                    :heading="__('Partido de vuelta -- decide el cruce')"
                                    :text="$match->firstLeg->home_score !== null
                                        ? __('Ida: :home-:away. El resultado global (ida + vuelta) es lo que define quién avanza.', ['home' => $match->firstLeg->home_score, 'away' => $match->firstLeg->away_score])
                                        : null"
                                />
                            @elseif ($match->isFirstLegOfTwoLeggedTie())
                                <flux:callout variant="secondary" icon="information-circle" class="mx-auto max-w-sm"
                                    :heading="__('Partido de ida')"
                                    :text="__('Cualquier resultado, incluido un empate, es válido: el cruce se decide con el resultado global una vez jugada la vuelta.')"
                                />
                            @endif

                            @if ($isDecisiveLeg)
                                <div class="mx-auto max-w-sm space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-white/10 dark:bg-white/5">
                                    <div class="space-y-1">
                                        <flux:checkbox
                                            x-model="wentToExtraTime"
                                            x-bind:disabled="! regulationIsDraw"
                                            label="{{ __('¿Se jugó una prórroga?') }}"
                                        />
                                        <flux:text x-show="! regulationIsDraw" x-cloak class="text-xs text-zinc-500 dark:text-white/50">
                                            {{ __('Solo puedes indicar una prórroga si el resultado quedó empatado.') }}
                                        </flux:text>
                                    </div>

                                    <div x-show="wentToExtraTime" x-cloak class="space-y-1.5">
                                        <flux:text class="text-center text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                            {{ __('Resultado de la prórroga') }}
                                        </flux:text>

                                        <div class="flex flex-nowrap items-end justify-center gap-3">
                                            <flux:input
                                                name="home_extra_time_score"
                                                type="number"
                                                min="0"
                                                label="{{ __('Local') }}"
                                                x-model="homeExtraTime"
                                                x-bind:disabled="! wentToExtraTime"
                                                error:message=""
                                                class="w-20"
                                            />

                                            <div class="pb-2.5 text-lg text-zinc-400 dark:text-white/30">&ndash;</div>

                                            <flux:input
                                                name="away_extra_time_score"
                                                type="number"
                                                min="0"
                                                label="{{ __('Visitante') }}"
                                                x-model="awayExtraTime"
                                                x-bind:disabled="! wentToExtraTime"
                                                error:message=""
                                                class="w-20"
                                            />
                                        </div>
                                    </div>

                                    <div x-show="regulationIsDraw" x-cloak class="space-y-1">
                                        <flux:checkbox
                                            x-model="wentToPenalties"
                                            x-bind:disabled="! canGoToPenalties"
                                            label="{{ __('¿Se definió por penales?') }}"
                                        />
                                        <flux:text x-show="wentToExtraTime && ! extraTimeIsDraw" x-cloak class="text-xs text-zinc-500 dark:text-white/50">
                                            {{ __('Solo puedes indicar penales si la prórroga también quedó empatada.') }}
                                        </flux:text>
                                    </div>

                                    <div x-show="wentToPenalties" x-cloak class="space-y-1.5">
                                        <flux:text class="text-center text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                            {{ __('Resultado de los penales') }}
                                        </flux:text>

                                        <div class="flex flex-nowrap items-end justify-center gap-3">
                                            <flux:input
                                                name="home_penalty_score"
                                                type="number"
                                                min="0"
                                                label="{{ __('Local') }}"
                                                value="{{ old('home_penalty_score', $match->home_penalty_score ?? '') }}"
                                                x-bind:disabled="! wentToPenalties"
                                                error:message=""
                                                class="w-20"
                                            />

                                            <div class="pb-2.5 text-lg text-zinc-400 dark:text-white/30">&ndash;</div>

                                            <flux:input
                                                name="away_penalty_score"
                                                type="number"
                                                min="0"
                                                label="{{ __('Visitante') }}"
                                                value="{{ old('away_penalty_score', $match->away_penalty_score ?? '') }}"
                                                x-bind:disabled="! wentToPenalties"
                                                error:message=""
                                                class="w-20"
                                            />
                                        </div>
                                    </div>
                                </div>
                            @endif

                            @if ($resultErrorMessage)
                                <flux:callout variant="danger" icon="exclamation-circle" :heading="$resultErrorMessage" />
                            @endif

                            <div class="flex justify-center">
                                <flux:button type="submit" variant="primary" icon="check" :loading="false">
                                    <span x-text="pending.length > 0 ? '{{ __('Registrar resultado y') }} ' + totalPendingCount() + ' {{ __('evento(s)') }}' : '{{ __('Registrar resultado') }}'">{{ __('Registrar resultado') }}</span>
                                </flux:button>
                            </div>
                        </form>

                        {{-- Only offered once a result actually exists --
                             resetting an already-scheduled match with
                             nothing recorded would have nothing to undo. --}}
                        @if ($match->status === \App\Enums\MatchStatus::Finished)
                            <div class="flex justify-center border-t border-zinc-200 px-4 py-4 dark:border-white/10 sm:px-8">
                                <x-ui.confirm-delete-form
                                    :action="route('matches.reset', $match)"
                                    method="PATCH"
                                    variant="warning"
                                    icon="arrow-path"
                                    :heading="__('¿Resetear este partido?')"
                                    :description="__('Se borran el resultado y TODOS los eventos registrados (goles, asistencias, tarjetas) de este partido. Esta acción no se puede deshacer.')"
                                    :confirm-label="__('Resetear partido')"
                                >
                                    <flux:button variant="ghost" size="sm" icon="arrow-path">{{ __('Resetear partido') }}</flux:button>
                                </x-ui.confirm-delete-form>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            @unless ($pending || $locked)
                <div class="space-y-4 lg:order-3">
                    <x-ui.match-ineligible-players :players="$awayIneligiblePlayers" :team="$match->awayTeam" />
                    <x-ui.match-roster-panel :team="$match->awayTeam" :players="$awayAvailablePlayers" :hide-coach="$awayCoachUnavailable" />
                    <x-ui.match-pending-tray :team-id="$match->away_team_id" />
                    <x-ui.match-unavailable-players :sanctions="$awayUnavailableSanctions" :match-id="$match->id" />
                </div>
            @endunless
        </div>

        @unless ($pending || $locked)
            <div class="mx-auto w-full max-w-2xl">
                {{-- Each queued event itself shows in a small "Por guardar"
                     card right under its own team's roster panel above
                     (x-ui.match-pending-tray) -- this is just the one submit
                     that saves everything at once, so no separate heading,
                     counter or empty-state text is needed here: the button's
                     own label already says whether there's anything queued. --}}
                <form method="POST" action="{{ route('matches.events.batch-store', $match) }}" class="flex justify-center">
                    @csrf

                    {{-- Only one of player_id/coach_id is ever emitted per row
                         (never both, never an empty placeholder for the
                         other) -- the field name itself is picked from
                         subjectType, so a coach event never sends a
                         player_id key at all. --}}
                    <template x-for="(event, index) in flatEvents()" :key="'f' + index">
                        <span>
                            <input type="hidden" :name="`events[${index}][type]`" :value="event.type">
                            <input type="hidden" :name="`events[${index}][${event.subjectType === 'coach' ? 'coach_id' : 'player_id'}]`" :value="event.subjectId">
                        </span>
                    </template>

                    {{-- :loading="false" is required: Flux defaults any
                         type="submit" button without a *literal* disabled
                         attribute at render time to loading=true (meant for
                         wire:submit forms), which then shows its spinner
                         instead of our label the instant Alpine's dynamic
                         x-bind:disabled actually applies disabled="true". --}}
                    <flux:button type="submit" variant="primary" icon="check" :loading="false" x-bind:disabled="pending.length === 0">
                        <span x-text="pending.length > 0 ? '{{ __('Guardar') }} ' + totalPendingCount() + ' {{ __('evento(s)') }}' : '{{ __('Sin eventos por guardar') }}'"></span>
                    </flux:button>
                </form>
            </div>
        @endunless
        </div>

        <div class="space-y-8">
            @unless ($locked)
                <div class="mx-auto w-full max-w-2xl">
                    <flux:separator variant="subtle" />
                </div>

                {{-- Wider than the rest of this column (max-w-4xl, not max-w-2xl)
                     -- the two-column event summary grid below needs more room
                     per card than a settings form does, so names and "Nx G"
                     badges stop crowding each other. --}}
                <div class="mx-auto w-full max-w-4xl space-y-4">
                    {{-- Registering events happens via the quick-add icons beside
                         the scoreboard now (x-ui.match-roster-panel) -- this
                         section is purely the read/edit/delete list of what's
                         already been saved. A walkover match never has any (it
                         was never actually played), so this whole section is
                         skipped entirely while locked instead of showing an
                         empty state that implies it could have events. --}}
                    <flux:heading size="lg">{{ __('Eventos del partido') }}</flux:heading>

                {{-- These react live to the Alpine `pending` queue (via
                     queuedGoalCount) so the mismatch appears/disappears as
                     goals are queued, without waiting for the batch save --
                     $goalCounts itself is only the already-saved DB count. --}}
                {{-- Compared against goalsScored() -- regular time PLUS extra
                     time, never penalties -- since a goal logged during a
                     prórroga is still a goal of this match. --}}
                @php $scored = $pending ? null : $match->goalsScored(); @endphp

                @if ($scored !== null)
                    @php
                        $wentToExtraTime = $match->home_extra_time_score !== null && $match->away_extra_time_score !== null;
                        $scoreLabel = fn (int $goals): string => $wentToExtraTime
                            ? __(':score, incluida la prórroga', ['score' => $goals])
                            : (string) $goals;
                        $homeMismatchPrefix = \Illuminate\Support\Js::from(__('Los goles registrados como eventos de :team', ['team' => $match->homeTeam->name]));
                        $homeMismatchSuffix = \Illuminate\Support\Js::from(__('no coinciden con el marcador (:score).', ['score' => $scoreLabel($scored['home'])]));
                        $awayMismatchPrefix = \Illuminate\Support\Js::from(__('Los goles registrados como eventos de :team', ['team' => $match->awayTeam->name]));
                        $awayMismatchSuffix = \Illuminate\Support\Js::from(__('no coinciden con el marcador (:score).', ['score' => $scoreLabel($scored['away'])]));
                    @endphp

                    <div x-show="({{ $goalCounts['home'] }} + queuedGoalCount({{ $match->home_team_id }})) !== {{ $scored['home'] }}" x-cloak>
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            <flux:callout.heading>
                                {{-- Server-rendered text is the fallback for
                                     no-JS/tests; x-text overwrites it the
                                     instant Alpine initializes, then keeps it
                                     live as goals are queued/unqueued. --}}
                                <span x-text="{{ $homeMismatchPrefix }} + ' (' + ({{ $goalCounts['home'] }} + queuedGoalCount({{ $match->home_team_id }})) + ') ' + {{ $homeMismatchSuffix }}">{{ __('Los goles registrados como eventos de :team (:count) no coinciden con el marcador (:score).', ['team' => $match->homeTeam->name, 'count' => $goalCounts['home'], 'score' => $scoreLabel($scored['home'])]) }}</span>
                            </flux:callout.heading>
                        </flux:callout>
                    </div>

                    <div x-show="({{ $goalCounts['away'] }} + queuedGoalCount({{ $match->away_team_id }})) !== {{ $scored['away'] }}" x-cloak>
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            <flux:callout.heading>
                                <span x-text="{{ $awayMismatchPrefix }} + ' (' + ({{ $goalCounts['away'] }} + queuedGoalCount({{ $match->away_team_id }})) + ') ' + {{ $awayMismatchSuffix }}">{{ __('Los goles registrados como eventos de :team (:count) no coinciden con el marcador (:score).', ['team' => $match->awayTeam->name, 'count' => $goalCounts['away'], 'score' => $scoreLabel($scored['away'])]) }}</span>
                            </flux:callout.heading>
                        </flux:callout>
                    </div>
                @endif

                @if ($pending)
                    <x-ui.empty-state icon="bolt" :message="__('Todavía no se pueden registrar eventos: faltan definir los equipos de este partido.')" />
                @elseif ($match->events->isEmpty())
                    <x-ui.empty-state icon="bolt" :message="__('Todavía no hay eventos registrados en este partido.')" />
                @else
                    @php
                        // One group per subject (player or coach) so the list
                        // below shows one accumulated row per person -- "2x G,
                        // 1x A, 1x TA" -- instead of one row per stored event.
                        $subjectKey = fn ($event) => $event->player_id !== null ? 'player-'.$event->player_id : 'coach-'.$event->coach_id;
                        $homeEventGroups = $match->events->where('team_id', $match->home_team_id)->groupBy($subjectKey);
                        $awayEventGroups = $match->events->where('team_id', $match->away_team_id)->groupBy($subjectKey);
                    @endphp

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
                            <div class="border-b border-zinc-200 px-4 py-2.5 dark:border-white/10">
                                <flux:heading size="sm">{{ __('Local') }} · {{ $match->homeTeam->name }}</flux:heading>
                            </div>
                            <div class="divide-y divide-zinc-100 dark:divide-white/5">
                                @forelse ($homeEventGroups as $group)
                                    <x-ui.match-event-row :events="$group" />
                                @empty
                                    <div class="px-4 py-4 text-sm text-zinc-400 dark:text-white/40">{{ __('Sin eventos.') }}</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
                            <div class="border-b border-zinc-200 px-4 py-2.5 dark:border-white/10">
                                <flux:heading size="sm">{{ __('Visitante') }} · {{ $match->awayTeam->name }}</flux:heading>
                            </div>
                            <div class="divide-y divide-zinc-100 dark:divide-white/5">
                                @forelse ($awayEventGroups as $group)
                                    <x-ui.match-event-row :events="$group" />
                                @empty
                                    <div class="px-4 py-4 text-sm text-zinc-400 dark:text-white/40">{{ __('Sin eventos.') }}</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
                </div>
            @endunless

            <div class="mx-auto w-full max-w-2xl space-y-8">
                <flux:separator variant="subtle" />

                <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
                    <flux:heading size="sm" class="mb-4">{{ __('Detalles del partido') }}</flux:heading>

                    @if ($locked)
                        <div class="space-y-6">
                            @include('pages.matches._fields', ['readonly' => true])
                        </div>

                        <flux:button :href="route('phases.show', $match->competitionPhase)" variant="ghost" wire:navigate class="mt-6">
                            {{ __('Volver') }}
                        </flux:button>
                    @else
                        <form method="POST" action="{{ route('matches.update', $match) }}" class="space-y-6">
                            @csrf
                            @method('PUT')

                            @include('pages.matches._fields')

                            <div class="flex items-center gap-3">
                                <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                                <flux:button :href="route('phases.show', $match->competitionPhase)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                            </div>
                        </form>
                    @endif
                </div>

                @unless ($locked)
                    <flux:separator variant="subtle" />

                    <x-ui.confirm-delete-form
                        :action="route('matches.destroy', $match)"
                        :heading="__('¿Eliminar este partido?')"
                        :description="__('Se eliminarán también sus eventos y sanciones asociadas. Esta acción no se puede deshacer.')"
                        :confirm-label="__('Eliminar partido')"
                    >
                        <flux:button variant="danger" icon="trash">{{ __('Eliminar partido') }}</flux:button>
                    </x-ui.confirm-delete-form>
                @endunless
            </div>
        </div>
    </div>
</x-layouts::app>
