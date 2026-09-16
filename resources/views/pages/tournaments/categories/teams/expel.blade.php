<x-layouts::app :title="__('Expulsar plantel')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Expulsar plantel')" :subtitle="$team->name" :eyebrow="$tournament->name.' · '.$category->name">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Mis torneos'), 'href' => route('dashboard')],
                    ['label' => $tournament->name, 'href' => route('tournaments.show', $tournament)],
                    ['label' => $category->name, 'href' => route('tournaments.categories.show', [$tournament, $category])],
                    ['label' => __('Expulsar :team', ['team' => $team->name])],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Esta acción afecta el calendario')">
            {{ __('Todos los partidos de :team que todavía no se jugaron en :category (:tournament) van a quedar como 0-3 en contra, marcados como "Perdido por W", y los puntos se le dan al rival. Los partidos ya jugados no se tocan, y esto no afecta a ningún otro plantel del club en otra categoría ni en otro torneo.', ['team' => $team->name, 'category' => $category->name, 'tournament' => $tournament->name]) }}
        </flux:callout>

        @if ($pendingMatches->isEmpty())
            <x-ui.empty-state icon="calendar-days" :message="__(':team no tiene partidos pendientes en esta categoría -- la expulsión solo va a quedar registrada, sin cambios en el calendario.', ['team' => $team->name])" />
        @else
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Partidos que quedarán 0-3 (:count)', ['count' => $pendingMatches->count()]) }}</flux:heading>

                <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                    @foreach ($pendingMatches as $match)
                        <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                            <div class="min-w-0 truncate text-zinc-800 dark:text-white">
                                {{ $match->homeTeam->name }}
                                <span class="text-zinc-400 dark:text-white/40">vs</span>
                                {{ $match->awayTeam->name }}
                            </div>

                            <flux:badge size="sm" :color="$match->status->color()">{{ $match->status->label() }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('tournaments.categories.teams.expel.store', [$tournament, $category, $team]) }}" class="space-y-4">
            @csrf

            <flux:textarea name="reason" label="{{ __('Motivo (opcional)') }}" rows="3">{{ old('reason') }}</flux:textarea>

            <div class="flex justify-end gap-2">
                <flux:button :href="route('tournaments.categories.show', [$tournament, $category])" variant="ghost" wire:navigate>
                    {{ __('Cancelar') }}
                </flux:button>

                <flux:button type="submit" variant="danger" icon="exclamation-triangle">
                    {{ __('Expulsar plantel') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
