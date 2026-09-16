<x-layouts::app :title="__('Sanciones')">
    <div class="w-full space-y-10 animate-fade-in-up">
        <x-ui.page-header :title="__('Sanciones')" :subtitle="__('Sanciones disciplinarias generadas a partir de las tarjetas registradas en tus partidos, y planteles expulsados de sus torneos.')" />

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @if ($sanctions->isEmpty() && empty($expelledTeams))
            <x-ui.empty-state icon="shield-exclamation" :message="__('Todavía no hay sanciones registradas. Se generan automáticamente cuando registras una doble amarilla o una roja directa en un partido, o cuando expulsas un plantel de un torneo.')" />
        @else
            {{-- Kept as two clearly separate sections -- a player/DT sanction
                 (pending -> resolved -> served fechas, see Sanction's own
                 docblock) and a team expulsion (an immediate, all-at-once
                 action, see TeamExpulsionService) are different enough kinds
                 of "sanción" that mixing them into one list would be
                 confusing rather than convenient. --}}
            <div class="space-y-6">
                <flux:heading size="xl">{{ __('Sanciones a jugadores y DTs') }}</flux:heading>

                @if ($sanctions->isEmpty())
                    <x-ui.empty-state icon="shield-exclamation" :message="__('Todavía no hay sanciones a jugadores o DTs. Se generan automáticamente cuando registras una doble amarilla o una roja directa en un partido.')" />
                @else
                    <div class="mx-auto grid grid-cols-3 gap-4 sm:gap-5 lg:max-w-2xl">
                        <x-ui.stat-card :label="__('Pendientes')" :value="$pendingSanctions->count()" icon="clock" color="amber" />
                        <x-ui.stat-card :label="__('Activas')" :value="$activeSanctions->count()" icon="shield-exclamation" color="red" />
                        <x-ui.stat-card :label="__('Cumplidas')" :value="$fulfilledSanctions->count()" icon="check-circle" color="green" />
                    </div>

                    {{-- Ordered todo -> en curso -> hecho: what needs the organizer's
                         attention first, then what's actively being served, then
                         what's already resolved and finished -- same priority the
                         stat cards above already read left to right. --}}
                    @if ($pendingSanctions->isNotEmpty())
                        <div class="space-y-4">
                            <flux:heading size="lg">{{ __('Faltan por resolver') }}</flux:heading>

                            <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                                @foreach ($pendingSanctions as $sanction)
                                    <x-ui.sanction-row :sanction="$sanction" />
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($activeSanctions->isNotEmpty())
                        <div class="space-y-4">
                            <flux:heading size="lg">{{ __('Ya tienen resolución') }}</flux:heading>

                            <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                                @foreach ($activeSanctions as $sanction)
                                    <x-ui.sanction-row :sanction="$sanction" />
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($fulfilledSanctions->isNotEmpty())
                        <div class="space-y-4">
                            <flux:heading size="lg">{{ __('Ya cumplidas') }}</flux:heading>

                            <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                                @foreach ($fulfilledSanctions as $sanction)
                                    <x-ui.sanction-row :sanction="$sanction" />
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
            </div>

            <flux:separator variant="subtle" />

            <div class="space-y-4">
                <flux:heading size="xl">{{ __('Planteles expulsados') }}</flux:heading>

                @if (empty($expelledTeams))
                    <x-ui.empty-state icon="no-symbol" :message="__('Todavía no expulsaste ningún plantel. Se hace desde la categoría de un torneo, en la ficha de cada equipo inscrito.')" />
                @else
                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                        @foreach ($expelledTeams as $expulsion)
                            @php [$expelledTeam, $expelledTournament] = [$expulsion['team'], $expulsion['tournament']]; @endphp

                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:link :href="route('teams.show', $expelledTeam)" wire:navigate class="truncate text-sm font-medium">
                                            {{ $expelledTeam->name }}
                                        </flux:link>
                                        <flux:badge size="sm" color="red">{{ __('Expulsado') }}</flux:badge>
                                    </div>

                                    <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-white/50">
                                        {{ $expelledTournament->name }} &middot; {{ $expelledTeam->category->name }}
                                        &middot; {{ \Illuminate\Support\Carbon::parse($expulsion['expelled_at'])->format('d/m/Y') }}
                                    </div>

                                    @if ($expulsion['reason'])
                                        <div class="mt-1 truncate text-xs text-zinc-500 dark:text-white/50">
                                            {{ $expulsion['reason'] }}
                                        </div>
                                    @endif
                                </div>

                                <form method="POST" action="{{ route('tournaments.categories.teams.expel.destroy', [$expelledTournament, $expelledTeam->category, $expelledTeam]) }}" class="shrink-0">
                                    @csrf
                                    @method('DELETE')

                                    <flux:button type="submit" variant="ghost" size="sm" icon="arrow-uturn-left">
                                        {{ __('Revertir') }}
                                    </flux:button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-layouts::app>
