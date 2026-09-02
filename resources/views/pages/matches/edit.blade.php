<x-layouts::app :title="__('Editar partido')">
    <div class="mx-auto w-full max-w-2xl space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Editar partido')" :subtitle="$match->competitionPhase->name" />

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @php
            $pending = $match->home_team_id === null || $match->away_team_id === null;
            $homeInitials = $match->homeTeam ? \Illuminate\Support\Str::substr($match->homeTeam->short_name ?: $match->homeTeam->name, 0, 2) : '?';
            $awayInitials = $match->awayTeam ? \Illuminate\Support\Str::substr($match->awayTeam->short_name ?: $match->awayTeam->name, 0, 2) : '?';
            $isKnockoutMatch = $match->competitionPhase->type !== \App\Enums\CompetitionPhaseType::League;

            $resultErrorKeys = ['home_score', 'away_score', 'home_extra_time_score', 'away_extra_time_score', 'home_penalty_score', 'away_penalty_score'];
            $resultErrorMessage = collect($resultErrorKeys)->map(fn ($key) => $errors->first($key))->first(fn ($message) => $message !== '');
        @endphp

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

            @if ($pending)
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
                        wentToExtraTime: {{ \Illuminate\Support\Js::from(old('home_extra_time_score', $match->home_extra_time_score) !== null) }},
                        homeExtraTime: '{{ old('home_extra_time_score', $match->home_extra_time_score ?? '') }}',
                        awayExtraTime: '{{ old('away_extra_time_score', $match->away_extra_time_score ?? '') }}',
                        wentToPenalties: {{ \Illuminate\Support\Js::from(old('home_penalty_score', $match->home_penalty_score) !== null) }},
                        get regulationIsDraw() {
                            return this.homeScore !== '' && this.awayScore !== '' && Number(this.homeScore) === Number(this.awayScore);
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

                    <div class="flex flex-wrap items-end justify-center gap-4">
                        <flux:input
                            name="home_score"
                            type="number"
                            min="0"
                            label="{{ $match->homeTeam->name }}"
                            x-model="homeScore"
                            error:message=""
                            class="w-24"
                        />

                        <div class="pb-2.5 text-lg text-zinc-400 dark:text-white/30">&ndash;</div>

                        <flux:input
                            name="away_score"
                            type="number"
                            min="0"
                            label="{{ $match->awayTeam->name }}"
                            x-model="awayScore"
                            error:message=""
                            class="w-24"
                        />
                    </div>

                    @if ($isKnockoutMatch)
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
                        <flux:button type="submit" variant="primary" icon="check">{{ __('Registrar resultado') }}</flux:button>
                    </div>
                </form>
            @endif
        </div>

        <flux:separator variant="subtle" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <flux:heading size="sm" class="mb-4">{{ __('Detalles del partido') }}</flux:heading>

            <form method="POST" action="{{ route('matches.update', $match) }}" class="space-y-6">
                @csrf
                @method('PUT')

                @include('pages.matches._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                    <flux:button :href="route('phases.show', $match->competitionPhase)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        </div>

        <flux:separator variant="subtle" />

        <form method="POST" action="{{ route('matches.destroy', $match) }}" onsubmit="return confirm('{{ __('¿Eliminar este partido?') }}')">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar partido') }}</flux:button>
        </form>
    </div>
</x-layouts::app>
