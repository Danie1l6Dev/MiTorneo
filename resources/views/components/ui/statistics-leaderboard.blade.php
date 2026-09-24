@props([
    'rows' => [],
    'type' => null,
    'showGroup' => false,
    'perPage' => 10,
])

{{--
    Pagination is purely client-side: every row is already rendered (the
    leaderboard is computed up front, see PhaseBoardService::statisticsPanels()),
    Alpine just shows $perPage of them at a time -- switching pages never
    reloads anything. Each panel (type x scope x group) is its own instance,
    so each keeps its own page.

    Row separators are a static border-t on every row that isn't the first
    of its page, instead of divide-y: divide-y keys off the DOM's real
    :last-child, which the rows hidden by x-show would still be, leaving a
    stray line under the last visible row of every page but the last.
--}}
@if (empty($rows))
    <x-ui.empty-state icon="chart-bar" :message="$type->emptyStatisticsMessage()" />
@else
    @php $pageCount = (int) ceil(count($rows) / $perPage); @endphp

    <div x-data="{ page: 1, pages: {{ $pageCount }} }" class="space-y-3">
        <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
            @foreach ($rows as $index => $row)
                @php $isLeader = $row['rank'] === 1; @endphp

                <div
                    @if ($pageCount > 1) x-show="page === {{ intdiv($index, $perPage) + 1 }}" @endif
                    @if ($index >= $perPage) x-cloak @endif
                    @class([
                        'flex items-center gap-4 px-4 py-3',
                        'border-t border-zinc-100 dark:border-white/5' => $index % $perPage !== 0,
                        'bg-amber-500/5' => $isLeader,
                    ])
                >
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

        @if ($pageCount > 1)
            <div class="flex items-center justify-center gap-4">
                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="chevron-left"
                    x-on:click="page = Math.max(1, page - 1)"
                    x-bind:disabled="page <= 1"
                />

                <flux:text class="min-w-28 text-center text-sm font-semibold text-zinc-700 dark:text-white/85"
                    x-text="'{{ __('Página') }} ' + page + ' {{ __('de') }} ' + pages">
                    {{ __('Página :page de :pages', ['page' => 1, 'pages' => $pageCount]) }}
                </flux:text>

                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="chevron-right"
                    x-on:click="page = Math.min(pages, page + 1)"
                    x-bind:disabled="page >= pages"
                />
            </div>
        @endif
    </div>
@endif
