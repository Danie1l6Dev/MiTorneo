<x-layouts::public :title="__('Sanciones')">
    <div class="w-full space-y-10 animate-fade-in-up">
        <x-ui.page-header :title="__('Sanciones')" :subtitle="__('Sanciones disciplinarias vigentes en :tournament.', ['tournament' => $tournament->name])">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => $tournament->name, 'href' => route('public.tournaments.show', $tournament)],
                    ['label' => __('Sanciones')],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        <flux:separator variant="subtle" />

        @if ($sanctions->isEmpty() && $expelledTeams->isEmpty())
            <x-ui.empty-state icon="shield-exclamation" :message="__('Todavía no hay sanciones registradas en este torneo.')" />
        @else
            <div class="space-y-6">
                <flux:heading size="xl">{{ __('Sanciones a jugadores y DTs') }}</flux:heading>

                @if ($sanctions->isEmpty())
                    <x-ui.empty-state icon="shield-exclamation" :message="__('Todavía no hay sanciones a jugadores o DTs en este torneo.')" />
                @else
                    <div class="mx-auto grid grid-cols-3 gap-4 sm:gap-5 lg:max-w-2xl">
                        <x-ui.stat-card :label="__('Pendientes')" :value="$pendingSanctions->count()" icon="clock" color="amber" />
                        <x-ui.stat-card :label="__('Activas')" :value="$activeSanctions->count()" icon="shield-exclamation" color="red" />
                        <x-ui.stat-card :label="__('Cumplidas')" :value="$fulfilledSanctions->count()" icon="check-circle" color="green" />
                    </div>

                    @if ($pendingSanctions->isNotEmpty())
                        <div class="space-y-4">
                            <flux:heading size="lg">{{ __('Faltan por resolver') }}</flux:heading>

                            <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                                @foreach ($pendingSanctions as $sanction)
                                    <x-ui.public-sanction-row :tournament="$tournament" :sanction="$sanction" />
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($activeSanctions->isNotEmpty())
                        <div class="space-y-4">
                            <flux:heading size="lg">{{ __('Ya tienen resolución') }}</flux:heading>

                            <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                                @foreach ($activeSanctions as $sanction)
                                    <x-ui.public-sanction-row :tournament="$tournament" :sanction="$sanction" />
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($fulfilledSanctions->isNotEmpty())
                        <div class="space-y-4">
                            <flux:heading size="lg">{{ __('Ya cumplidas') }}</flux:heading>

                            <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                                @foreach ($fulfilledSanctions as $sanction)
                                    <x-ui.public-sanction-row :tournament="$tournament" :sanction="$sanction" />
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
            </div>

            <flux:separator variant="subtle" />

            <div class="space-y-4">
                <flux:heading size="xl">{{ __('Planteles expulsados') }}</flux:heading>

                @if ($expelledTeams->isEmpty())
                    <x-ui.empty-state icon="no-symbol" :message="__('Ningún plantel fue expulsado de este torneo.')" />
                @else
                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                        @foreach ($expelledTeams as $expelledTeam)
                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:link :href="route('public.tournaments.teams.show', [$tournament, $expelledTeam])" wire:navigate class="truncate text-sm font-medium">
                                            {{ $expelledTeam->name }}
                                        </flux:link>
                                        <flux:badge size="sm" color="red">{{ __('Expulsado') }}</flux:badge>
                                    </div>

                                    <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-white/50">
                                        {{ $expelledTeam->category->name }}
                                        &middot; {{ \Illuminate\Support\Carbon::parse($expelledTeam->pivot->getAttribute('expelled_at'))->format('d/m/Y') }}
                                    </div>

                                    @if ($expelledTeam->pivot->getAttribute('expulsion_reason'))
                                        <div class="mt-1 truncate text-xs text-zinc-500 dark:text-white/50">
                                            {{ $expelledTeam->pivot->getAttribute('expulsion_reason') }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-layouts::public>
