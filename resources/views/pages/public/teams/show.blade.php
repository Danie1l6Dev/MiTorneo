@php
    $subtitle = collect([$team->short_name, $team->category->name, $team->group?->name])
        ->filter()
        ->implode(' · ');

    $coachSanctions = $team->coach ? $activeSanctionsBySubject->get('coach-'.$team->coach->id) : null;
@endphp

<x-layouts::public :title="$team->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$team->name" :subtitle="$subtitle">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => $tournament->name, 'href' => route('public.tournaments.show', $tournament)],
                    ['label' => $team->category->name, 'href' => route('public.tournaments.categories.show', [$tournament, $team->category])],
                    ['label' => $team->name],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        @if ($isExpelled)
            <flux:callout variant="danger" icon="no-symbol" :heading="__('Expulsado de :tournament', ['tournament' => $tournament->name])">
                @if (! $expulsionResolutionPdfUrl && $expulsionReason)
                    {{ $expulsionReason }}
                @endif
                @if ($expelledAt)
                    <div class="mt-1 text-xs opacity-70">{{ $expelledAt->format('d/m/Y') }}</div>
                @endif
            </flux:callout>

            @if ($expulsionResolutionPdfUrl)
                <div class="space-y-2 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                    <flux:heading size="lg">{{ __('Resolución del comité') }}</flux:heading>

                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-white/10">
                        <embed src="{{ $expulsionResolutionPdfUrl }}" type="application/pdf" class="h-[80vh] w-full" />
                    </div>

                    <flux:button href="{{ $expulsionResolutionPdfUrl }}" download="{{ $team->expulsionResolutionPdfDownloadName() }}" icon="arrow-down-tray" size="sm">
                        {{ __('Descargar PDF') }}
                    </flux:button>
                </div>
            @endif
        @endif

        <flux:separator variant="subtle" />

        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Director técnico') }}</flux:heading>

            @if ($team->coach)
                <div class="flex items-center justify-between gap-3 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-accent-content/15 text-accent-content">
                            <flux:icon.identification variant="micro" class="size-5" />
                        </div>
                        <div class="truncate text-sm font-semibold text-zinc-800 dark:text-white">{{ $team->coach->full_name }}</div>
                    </div>

                    @if ($coachSanctions)
                        <flux:badge size="sm" color="red">{{ $coachSanctions->first()->stateLabel() }}</flux:badge>
                    @endif
                </div>
            @else
                <flux:text class="text-zinc-500 dark:text-white/60">{{ __('No registrado') }}</flux:text>
            @endif
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Jugadores') }}</flux:heading>

            @if ($roster->isEmpty())
                <x-ui.empty-state icon="user-group" :message="__('Todavía no hay jugadores registrados en este equipo.')" />
            @else
                <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                    @foreach ($roster as $player)
                        @php $playerSanctions = $activeSanctionsBySubject->get('player-'.$player->id); @endphp

                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent-content/15 font-display text-base font-bold tabular-nums text-accent-content">
                                    {{ $player->jersey_number ?? '–' }}
                                </div>

                                <div class="min-w-0 truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $player->full_name }}</div>
                            </div>

                            @if ($playerSanctions)
                                <flux:badge size="sm" color="red">{{ $playerSanctions->first()->stateLabel() }}</flux:badge>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::public>
