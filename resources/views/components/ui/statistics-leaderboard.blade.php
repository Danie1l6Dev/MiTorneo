@props([
    'rows' => [],
    'type' => null,
    'showGroup' => false,
])

@if (empty($rows))
    <x-ui.empty-state icon="chart-bar" :message="$type->emptyStatisticsMessage()" />
@else
    <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
        @foreach ($rows as $row)
            @php $isLeader = $row['rank'] === 1; @endphp

            <div @class([
                'flex items-center gap-4 px-4 py-3',
                'bg-amber-500/5' => $isLeader,
            ])>
                <div @class([
                    'flex size-9 shrink-0 items-center justify-center rounded-full font-display text-sm font-bold tabular-nums',
                    'bg-amber-500/15 text-amber-500' => $isLeader,
                    'bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-white/60' => ! $isLeader,
                ])>
                    {{ str_pad((string) $row['rank'], 2, '0', STR_PAD_LEFT) }}
                </div>

                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold text-zinc-800 dark:text-white">{{ $row['player']->full_name }}</div>
                    <div class="flex flex-wrap items-center gap-x-1.5 truncate text-xs text-zinc-500 dark:text-white/50">
                        <span class="truncate">{{ $row['player']->team->name }}</span>

                        @if ($showGroup && $row['player']->team->group)
                            <span aria-hidden="true">&middot;</span>
                            <span class="truncate">{{ $row['player']->team->group->name }}</span>
                        @endif
                    </div>
                </div>

                <div class="shrink-0 text-right">
                    <span @class([
                        'font-display text-lg font-bold tabular-nums',
                        'text-amber-500' => $isLeader,
                        'text-zinc-900 dark:text-white' => ! $isLeader,
                    ])>{{ $row['count'] }}</span>
                    <span class="ms-1 text-xs text-zinc-500 dark:text-white/50">{{ $type->statisticNoun() }}</span>
                </div>
            </div>
        @endforeach
    </div>
@endif
