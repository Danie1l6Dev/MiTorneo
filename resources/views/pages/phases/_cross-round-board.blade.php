@props(['board', 'public' => false, 'tournament' => null])

{{--
    A ronda de cruces laid out one column per group: who passed directly (no
    match to play) above the crosses played between that group's own teams.
    Crosses between teams of different groups are listed apart, below.
    Each cross is drawn with the bracket's own card (winner in bold, penalties
    and extra time shown on it). Shared by the admin phase page and the public
    portal; only the match link differs.
--}}
@php
    $columns = $board['columns'];
    $between = $board['between'];

    $matchUrl = fn ($match) => $public
        ? route('public.tournaments.matches.show', [$tournament, $match])
        : route('matches.edit', $match);

    $gridClasses = match (true) {
        count($columns) >= 3 => 'md:grid-cols-2 xl:grid-cols-3',
        count($columns) === 2 => 'md:grid-cols-2',
        default => 'max-w-xl mx-auto w-full',
    };
@endphp

<div class="grid items-start gap-6 {{ $gridClasses }}">
    @foreach ($columns as $column)
        <section class="space-y-4">
            <div class="flex items-center justify-between gap-3 rounded-2xl border border-zinc-200 bg-zinc-50/60 px-4 py-3 dark:border-white/10 dark:bg-white/[0.03]">
                <flux:heading size="sm" class="text-base!">{{ $column['label'] ?? __('Cruces') }}</flux:heading>

                <div class="flex items-center gap-1.5">
                    @if ($column['matches']->isNotEmpty())
                        <flux:badge size="sm" color="amber">{{ trans_choice(':count cruce|:count cruces', $column['matches']->count(), ['count' => $column['matches']->count()]) }}</flux:badge>
                    @endif
                    @if ($column['direct']->isNotEmpty())
                        <flux:badge size="sm" color="green">{{ trans_choice(':count pasa directo|:count pasan directo', $column['direct']->count(), ['count' => $column['direct']->count()]) }}</flux:badge>
                    @endif
                </div>
            </div>

            @if ($column['direct']->isNotEmpty())
                <div class="space-y-2 rounded-2xl border border-green-500/25 bg-green-500/5 p-4 dark:border-green-400/25">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-green-700 dark:text-green-400">
                        <flux:icon.check-circle variant="micro" class="size-4" />
                        {{ __('Pasan directo a la siguiente fase') }}
                    </div>

                    <ul class="grid gap-1.5 sm:grid-cols-2">
                        @foreach ($column['direct'] as $team)
                            <li class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm font-medium text-zinc-800 shadow-xs dark:bg-white/10 dark:text-white">
                                <span class="size-1.5 shrink-0 rounded-full bg-green-500"></span>
                                <span class="min-w-0 truncate">{{ $team->name }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($column['matches']->isNotEmpty())
                <div class="space-y-4">
                    @foreach ($column['matches'] as $match)
                        <div class="mx-auto w-full max-w-md">
                        <x-ui.bracket-match-card :match="$match" :href="$matchUrl($match)" card-class="h-20" row-class="h-10" text-class="text-base" :allow-picker="! $public" />
                    </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach
</div>

@if ($between->isNotEmpty())
    <section class="space-y-4">
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50/60 px-4 py-3 dark:border-white/10 dark:bg-white/[0.03]">
            <flux:heading size="sm" class="text-base!">{{ __('Cruces entre grupos') }}</flux:heading>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($between as $match)
                <div class="mx-auto w-full max-w-md">
                        <x-ui.bracket-match-card :match="$match" :href="$matchUrl($match)" card-class="h-20" row-class="h-10" text-class="text-base" :allow-picker="! $public" />
                    </div>
            @endforeach
        </div>
    </section>
@endif
