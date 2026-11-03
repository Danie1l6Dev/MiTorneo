@props(['losers', 'phase'])

{{--
    Ranking of the losers of a ronda de cruces (the "mejores perdedores"), with
    the teams level on everything marked and a Sortear button per tie, so ties
    are settled BEFORE choosing how many losers qualify.
--}}
@php
    $ties = [];

    foreach ($losers as $index => $loser) {
        if (($loser['tie'] ?? null) === null) {
            continue;
        }

        $key = implode('-', $loser['tie_teams']);
        $ties[$key] ??= ['first' => $index, 'last' => $index, 'row' => $loser];
        $ties[$key]['last'] = $index;
    }

    // One colour per tie block: Flux badge colour + literal row classes (Tailwind
    // only keeps classes it can see spelled out).
    $palette = [
        ['badge' => 'amber', 'row' => 'bg-amber-500/15'],
        ['badge' => 'sky', 'row' => 'bg-sky-500/15'],
        ['badge' => 'violet', 'row' => 'bg-violet-500/15'],
        ['badge' => 'emerald', 'row' => 'bg-emerald-500/15'],
        ['badge' => 'rose', 'row' => 'bg-rose-500/15'],
        ['badge' => 'orange', 'row' => 'bg-orange-500/15'],
    ];
    $tieColors = [];

    foreach (array_keys($ties) as $position => $key) {
        $tieColors[$key] = $palette[$position % count($palette)];
    }

    $pendingCount = collect($ties)->where('row.tie', 'pending')->count();
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="lg">{{ __('Mejores perdedores') }}</flux:heading>
        <flux:text class="text-sm text-zinc-500">{{ $pendingCount > 0 ? trans_choice(':count empate por resolver con sorteo|:count empates por resolver con sorteo', $pendingCount) : __('Ordenados por DG, GF, juego limpio y sorteo.') }}</flux:text>
    </div>

    <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-white/10 dark:text-white/50">
                    <th class="w-12 px-4 py-2.5 text-center">{{ __('Pos') }}</th>
                    <th class="px-2 py-2.5">{{ __('Equipo') }}</th>
                    <th class="px-2 py-2.5 text-center">{{ __('DG') }}</th>
                    <th class="px-2 py-2.5 text-center">{{ __('GF') }}</th>
                    <th class="px-4 py-2.5 text-center" title="{{ __('Puntos de juego limpio: menos es mejor') }}">{{ __('JL') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($losers as $index => $loser)
                    @php($tieColor = ($loser['tie'] ?? null) !== null ? $tieColors[implode('-', $loser['tie_teams'])] : null)
                    <tr class="border-b border-zinc-100 last:border-0 dark:border-white/5 {{ $tieColor['row'] ?? '' }}">
                        <td class="px-4 py-2.5 text-center text-zinc-500 dark:text-white/50">{{ $index + 1 }}</td>
                        <td class="px-2 py-2.5 font-medium text-zinc-800 dark:text-white">
                            <div class="flex items-center gap-2">
                                {{ $loser['team']->name }}
                                <x-ui.tie-controls :row="$loser" :previous="$losers[$index - 1] ?? null" :draw-phase="$phase" :color="$tieColor['badge'] ?? null" />
                            </div>
                        </td>
                        <td class="px-2 py-2.5 text-center tabular-nums text-zinc-600 dark:text-white/70">{{ $loser['goal_difference'] }}</td>
                        <td class="px-2 py-2.5 text-center tabular-nums text-zinc-600 dark:text-white/70">{{ $loser['goals_for'] }}</td>
                        <td class="px-4 py-2.5 text-center tabular-nums text-zinc-600 dark:text-white/70">{{ $loser['fair_play'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
