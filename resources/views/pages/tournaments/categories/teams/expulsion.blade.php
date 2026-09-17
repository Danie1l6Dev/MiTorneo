<x-layouts::app :title="$team->name">
    <div class="mx-auto w-full max-w-2xl space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$team->name" :subtitle="__('Expulsado del torneo')">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Mis torneos'), 'href' => route('dashboard')],
                    ['label' => $tournament->name, 'href' => route('tournaments.show', $tournament)],
                    ['label' => $category->name, 'href' => route('tournaments.categories.show', [$tournament, $category])],
                    ['label' => __('Expulsión: :team', ['team' => $team->name])],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        <div class="space-y-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
            <div class="flex items-center justify-between">
                <flux:badge size="sm" color="red">{{ __('Expulsado') }}</flux:badge>
            </div>

            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-zinc-500 dark:text-white/50">{{ __('Equipo') }}</dt>
                    <dd class="font-medium text-zinc-800 dark:text-white">
                        <flux:link :href="route('teams.show', $team)" wire:navigate>{{ $team->name }}</flux:link>
                    </dd>
                </div>

                <div>
                    <dt class="text-zinc-500 dark:text-white/50">{{ __('Torneo / categoría') }}</dt>
                    <dd class="font-medium text-zinc-800 dark:text-white">{{ $tournament->name }} &middot; {{ $category->name }}</dd>
                </div>

                <div>
                    <dt class="text-zinc-500 dark:text-white/50">{{ __('Expulsado el') }}</dt>
                    <dd class="font-medium text-zinc-800 dark:text-white">{{ $team->expelledAtFor($tournament)?->format('d/m/Y') }}</dd>
                </div>

                @php $resolutionPdfPath = $team->expulsionResolutionPdfPathFor($tournament); @endphp
                @if ($resolutionPdfPath || $team->expulsionReasonFor($tournament) || $pdfUploadsEnabled)
                    <div class="col-span-2 space-y-3">
                        <dt class="text-zinc-500 dark:text-white/50">{{ __('Resolución del comité') }}</dt>
                        <dd class="space-y-3">
                            @if ($resolutionPdfPath)
                                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-white/10">
                                    <embed src="{{ $team->expulsionResolutionPdfUrlFor($tournament) }}" type="application/pdf" class="h-[80vh] w-full" />
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:button href="{{ $team->expulsionResolutionPdfUrlFor($tournament) }}" download="{{ $team->expulsionResolutionPdfDownloadName() }}" icon="arrow-down-tray" size="sm">
                                        {{ __('Descargar PDF') }}
                                    </flux:button>

                                    <x-ui.confirm-delete-form :action="route('tournaments.categories.teams.expel.resolution-pdf.destroy', [$tournament, $category, $team])" :heading="__('¿Quitar el PDF de la resolución?')" :description="__('Podés subir uno nuevo después si hace falta.')" :confirm-label="__('Quitar PDF')">
                                        <flux:button variant="danger" icon="trash" size="sm">
                                            {{ __('Quitar PDF') }}
                                        </flux:button>
                                    </x-ui.confirm-delete-form>
                                </div>
                            @elseif ($team->expulsionReasonFor($tournament))
                                <p class="text-sm font-medium text-zinc-800 dark:text-white">{{ $team->expulsionReasonFor($tournament) }}</p>
                            @endif

                            @if ($pdfUploadsEnabled)
                                <form method="POST" action="{{ route('tournaments.categories.teams.expel.resolution-pdf.update', [$tournament, $category, $team]) }}" enctype="multipart/form-data" @class(['space-y-3', 'border-t border-zinc-200 pt-3 dark:border-white/10' => $resolutionPdfPath || $team->expulsionReasonFor($tournament)])>
                                    @csrf
                                    @method('PATCH')
                                    <flux:input type="file" name="resolution_pdf" :label="$resolutionPdfPath ? __('Reemplazar PDF') : __('Adjuntar PDF')" accept="application/pdf" />
                                    <div class="flex justify-end">
                                        <flux:button type="submit" size="sm">{{ $resolutionPdfPath ? __('Reemplazar') : __('Adjuntar') }}</flux:button>
                                    </div>
                                </form>
                            @endif
                        </dd>
                    </div>
                @endif
            </dl>
        </div>

        @if ($affectedMatches->isNotEmpty())
            <div class="space-y-2 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                <flux:heading size="lg">{{ __('Partidos perdidos por W') }}</flux:heading>

                <flux:text class="text-sm">{{ __('Los partidos que :team todavía no había jugado quedaron 0-3 en contra al expulsarlo.', ['team' => $team->name]) }}</flux:text>

                <div class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($affectedMatches as $affectedMatch)
                        <a href="{{ route('matches.edit', $affectedMatch) }}" wire:navigate class="flex items-center justify-between gap-3 py-2.5 transition-colors hover:opacity-80">
                            <div class="min-w-0 text-sm">
                                <span class="text-zinc-800 dark:text-white">{{ $affectedMatch->homeTeam?->name ?? __('Por definir') }}</span>
                                <span class="text-zinc-400 dark:text-white/40">vs</span>
                                <span class="text-zinc-800 dark:text-white">{{ $affectedMatch->awayTeam?->name ?? __('Por definir') }}</span>
                            </div>

                            <flux:badge size="sm" color="zinc">{{ __('0-3 (W)') }}</flux:badge>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex justify-end">
            <form method="POST" action="{{ route('tournaments.categories.teams.expel.destroy', [$tournament, $category, $team]) }}">
                @csrf
                @method('DELETE')

                <flux:button type="submit" variant="ghost" icon="arrow-uturn-left">
                    {{ __('Revertir expulsión') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::app>
