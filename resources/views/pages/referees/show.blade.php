<x-layouts::app :title="$referee->full_name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$referee->full_name" :subtitle="__('Documento: :document', ['document' => $referee->document_number])">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Árbitros'), 'href' => route('referees.index')],
                    ['label' => $referee->full_name],
                ]" />
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <flux:button :href="route('referees.edit', $referee)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar árbitro') }}
                </flux:button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        <div class="mx-auto grid grid-cols-2 gap-4 sm:gap-5 lg:max-w-md">
            <x-ui.stat-card :label="__('Partidos dirigidos')" :value="$matches->count()" icon="flag" color="accent" />
            <x-ui.stat-card :label="__('Torneos distintos')" :value="$matches->pluck('tournament_id')->unique()->count()" icon="trophy" color="green" />
        </div>

        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Partidos dirigidos') }}</flux:heading>

            @if ($matches->isEmpty())
                <x-ui.empty-state icon="flag" :message="__('Este árbitro todavía no ha dirigido ningún partido.')" />
            @else
                <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
                    {{-- Desktop --}}
                    <div class="hidden overflow-x-auto md:block">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-white/10 dark:text-white/50">
                                    <th class="px-4 py-2.5">{{ __('Torneo') }}</th>
                                    <th class="px-2 py-2.5">{{ __('Categoría') }}</th>
                                    <th class="px-2 py-2.5">{{ __('Fase') }}</th>
                                    <th class="px-2 py-2.5">{{ __('Fecha') }}</th>
                                    <th class="px-2 py-2.5">{{ __('Equipos') }}</th>
                                    <th class="px-4 py-2.5 text-center">{{ __('Resultado') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($matches as $match)
                                    <tr class="border-b border-zinc-100 last:border-0 hover:bg-zinc-50 dark:border-white/5 dark:hover:bg-white/5">
                                        <td class="px-4 py-3 font-medium text-zinc-800 dark:text-white">{{ $match->tournament->name }}</td>
                                        <td class="px-2 py-3 text-zinc-600 dark:text-white/70">{{ $match->category->name }}</td>
                                        <td class="px-2 py-3 text-zinc-600 dark:text-white/70">{{ $match->competitionPhase->name }}</td>
                                        <td class="px-2 py-3 whitespace-nowrap text-zinc-600 dark:text-white/70">{{ $match->scheduled_at?->format('d/m/Y H:i') ?? __('Sin fecha') }}</td>
                                        <td class="px-2 py-3">
                                            <flux:link :href="route('matches.edit', $match)" wire:navigate class="font-medium">
                                                {{ $match->homeTeam?->name ?? __('Por definir') }}
                                                <span class="text-zinc-400 dark:text-white/40">vs</span>
                                                {{ $match->awayTeam?->name ?? __('Por definir') }}
                                            </flux:link>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            @if ($match->home_score !== null && $match->away_score !== null)
                                                <span class="font-display font-bold tabular-nums text-zinc-900 dark:text-white">{{ $match->home_score }} - {{ $match->away_score }}</span>
                                            @else
                                                <flux:badge size="sm" :color="$match->status->color()">{{ $match->status->label() }}</flux:badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Mobile --}}
                    <div class="divide-y divide-zinc-100 dark:divide-white/5 md:hidden">
                        @foreach ($matches as $match)
                            <a href="{{ route('matches.edit', $match) }}" wire:navigate class="block px-4 py-3 transition-colors hover:bg-zinc-50 dark:hover:bg-white/5">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">
                                            {{ $match->homeTeam?->name ?? __('Por definir') }} vs {{ $match->awayTeam?->name ?? __('Por definir') }}
                                        </div>
                                        <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-white/50">
                                            {{ $match->tournament->name }} &middot; {{ $match->category->name }} &middot; {{ $match->competitionPhase->name }}
                                        </div>
                                        <div class="mt-0.5 text-xs text-zinc-400 dark:text-white/40">
                                            {{ $match->scheduled_at?->format('d/m/Y H:i') ?? __('Sin fecha') }}
                                        </div>
                                    </div>

                                    <div class="shrink-0 text-right">
                                        @if ($match->home_score !== null && $match->away_score !== null)
                                            <span class="font-display font-bold tabular-nums text-zinc-900 dark:text-white">{{ $match->home_score }} - {{ $match->away_score }}</span>
                                        @else
                                            <flux:badge size="sm" :color="$match->status->color()">{{ $match->status->label() }}</flux:badge>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
