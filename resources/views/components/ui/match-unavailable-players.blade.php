@props([
    // Collection<Sanction> -- sanctions that Sanction::blocksMatch($matchId)
    // says currently keep their subject out of THIS match (see
    // TournamentMatchController::edit()'s unavailableSanctions()). Never
    // includes the sanction whose match IS this one.
    'sanctions',
    // The match this panel is shown on -- needed so each row's label can
    // reflect how many fechas were served AS OF this specific match (see
    // Sanction::stateLabelForMatch()), not today's overall total.
    'matchId',
])

@if ($sanctions->isNotEmpty())
    <div class="overflow-hidden rounded-2xl border border-red-200 bg-red-50/60 dark:border-red-500/20 dark:bg-red-500/[0.04]">
        <div class="flex items-center gap-2 border-b border-red-200 px-4 py-2.5 dark:border-red-500/20">
            <x-tabler-ban class="size-4 text-red-500" />
            <flux:heading size="sm" class="text-red-700 dark:text-red-300">{{ __('Jugadores no disponibles') }}</flux:heading>
        </div>

        <div class="divide-y divide-red-100 dark:divide-red-500/10">
            @foreach ($sanctions as $sanction)
                <a
                    href="{{ route('sanctions.show', $sanction) }}"
                    wire:navigate
                    class="flex items-center justify-between gap-3 px-4 py-2.5 transition-colors hover:bg-red-100/60 dark:hover:bg-red-500/10"
                >
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $sanction->subjectLabel() }}</div>
                        <div class="mt-0.5 text-xs text-zinc-500 dark:text-white/50">{{ $sanction->stateLabelForMatch($matchId) }}</div>
                    </div>

                    <flux:badge size="sm" :color="$sanction->type->color()">{{ $sanction->type->label() }}</flux:badge>
                </a>
            @endforeach
        </div>
    </div>
@endif
