<x-layouts::public :title="$sanction->subjectLabel()">
    <div class="mx-auto w-full max-w-2xl space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$sanction->subjectLabel()" :subtitle="$sanction->type->label()">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => $tournament->name, 'href' => route('public.tournaments.show', $tournament)],
                    ['label' => __('Sanciones'), 'href' => route('public.tournaments.sanctions.index', $tournament)],
                    ['label' => $sanction->subjectLabel()],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        <div class="space-y-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
            <div class="flex items-center justify-between">
                <flux:badge size="sm" :color="$sanction->type->color()">{{ $sanction->type->label() }}</flux:badge>
                <flux:badge size="sm" :color="$sanction->status->color()">{{ $sanction->stateLabel() }}</flux:badge>
            </div>

            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-zinc-500 dark:text-white/50">{{ __('Equipo') }}</dt>
                    <dd class="font-medium text-zinc-800 dark:text-white">
                        <flux:link :href="route('public.tournaments.teams.show', [$tournament, $sanction->team])" wire:navigate>
                            {{ $sanction->team->name }}
                        </flux:link>
                    </dd>
                </div>

                <div>
                    <dt class="text-zinc-500 dark:text-white/50">{{ __('Categoría') }}</dt>
                    <dd class="font-medium text-zinc-800 dark:text-white">{{ $sanction->match->category->name }}</dd>
                </div>

                <div class="col-span-2">
                    <dt class="text-zinc-500 dark:text-white/50">{{ __('Partido de origen') }}</dt>
                    <dd class="font-medium text-zinc-800 dark:text-white">
                        <flux:link :href="route('public.tournaments.matches.show', [$tournament, $sanction->match])" wire:navigate>
                            {{ $sanction->match->homeTeam?->name ?? __('Por definir') }}
                            <span class="text-zinc-400 dark:text-white/40">vs</span>
                            {{ $sanction->match->awayTeam?->name ?? __('Por definir') }}
                        </flux:link>
                    </dd>
                </div>

                @if ($sanction->isResolved())
                    <div>
                        <dt class="text-zinc-500 dark:text-white/50">{{ __('Fechas de sanción') }}</dt>
                        <dd class="font-medium text-zinc-800 dark:text-white">{{ $sanction->matchesServedCount() }} / {{ $sanction->matches_banned }}</dd>
                    </div>

                    <div>
                        <dt class="text-zinc-500 dark:text-white/50">{{ __('Resuelta el') }}</dt>
                        <dd class="font-medium text-zinc-800 dark:text-white">{{ $sanction->resolved_at?->format('d/m/Y') }}</dd>
                    </div>

                    @if ($sanction->resolution_pdf_path)
                        <div class="col-span-2 space-y-2">
                            <dt class="text-zinc-500 dark:text-white/50">{{ __('Resolución del comité') }}</dt>
                            <dd>
                                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-white/10">
                                    <embed src="{{ $sanction->resolutionPdfUrl() }}" type="application/pdf" class="h-[80vh] w-full" />
                                </div>
                                <flux:button href="{{ $sanction->resolutionPdfUrl() }}" download="{{ $sanction->resolutionPdfDownloadName() }}" icon="arrow-down-tray" size="sm" class="mt-2">
                                    {{ __('Descargar PDF') }}
                                </flux:button>
                            </dd>
                        </div>
                    @elseif ($sanction->resolution_notes)
                        <div class="col-span-2">
                            <dt class="text-zinc-500 dark:text-white/50">{{ __('Motivo / observación') }}</dt>
                            <dd class="font-medium text-zinc-800 dark:text-white">{{ $sanction->resolution_notes }}</dd>
                        </div>
                    @endif
                @endif
            </dl>
        </div>

        @if ($sanction->isPending())
            <flux:callout variant="warning" icon="clock" :heading="__('Pendiente de resolución')">
                {{ __('El comité deportivo todavía no resolvió esta sanción.') }}
            </flux:callout>
        @else
            @php $servingMatches = $sanction->servingWindowMatches(); @endphp

            @if ($servingMatches->isNotEmpty())
                <div class="space-y-2 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                    <flux:heading size="lg">{{ __('Fechas de la sanción') }}</flux:heading>

                    <div class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach ($servingMatches as $servingMatch)
                            <a href="{{ route('public.tournaments.matches.show', [$tournament, $servingMatch]) }}" wire:navigate class="flex items-center justify-between gap-3 py-2.5 transition-colors hover:opacity-80">
                                <div class="min-w-0 text-sm">
                                    <span class="text-zinc-800 dark:text-white">{{ $servingMatch->homeTeam?->name ?? __('Por definir') }}</span>
                                    <span class="text-zinc-400 dark:text-white/40">vs</span>
                                    <span class="text-zinc-800 dark:text-white">{{ $servingMatch->awayTeam?->name ?? __('Por definir') }}</span>
                                </div>

                                @if ($servingMatch->status === \App\Enums\MatchStatus::Finished)
                                    <flux:badge size="sm" color="green">{{ __('Cumplida') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" :color="$servingMatch->status->color()">{{ $servingMatch->status->label() }}</flux:badge>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-layouts::public>
