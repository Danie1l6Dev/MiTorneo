@props([
    'label' => null,
    'rows' => [],
])

<div {{ $attributes->class('overflow-hidden rounded-xl border border-zinc-200 dark:border-white/10 glass-panel') }}>
    @if ($label)
        <div class="border-b border-zinc-200 px-4 py-3 dark:border-white/10">
            <flux:heading size="sm">{{ $label }}</flux:heading>
        </div>
    @endif

    @if (count($rows) === 0)
        <div class="p-6">
            <x-ui.empty-state icon="table-cells" :message="__('Todavía no hay equipos para esta tabla.')" />
        </div>
    @else
        {{-- Desktop --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-white/10 dark:text-white/50">
                        <th class="w-12 px-4 py-2.5 text-center">{{ __('Pos') }}</th>
                        <th class="px-2 py-2.5">{{ __('Equipo') }}</th>
                        <th class="px-2 py-2.5 text-center">{{ __('PJ') }}</th>
                        <th class="px-2 py-2.5 text-center">{{ __('PG') }}</th>
                        <th class="px-2 py-2.5 text-center">{{ __('PE') }}</th>
                        <th class="px-2 py-2.5 text-center">{{ __('PP') }}</th>
                        <th class="px-2 py-2.5 text-center">{{ __('GF') }}</th>
                        <th class="px-2 py-2.5 text-center">{{ __('GC') }}</th>
                        <th class="px-2 py-2.5 text-center">{{ __('DG') }}</th>
                        <th class="px-4 py-2.5 text-center">{{ __('PTS') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $index => $row)
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-white/5 {{ $index === 0 ? 'bg-accent/10' : '' }}">
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex size-6 items-center justify-center rounded-full text-xs font-bold {{ $index === 0 ? 'bg-accent text-accent-foreground' : 'text-zinc-500 dark:text-white/50' }}">
                                    {{ $index + 1 }}
                                </span>
                            </td>
                            <td class="px-2 py-2.5 font-medium text-zinc-800 dark:text-white">{{ $row['team']->name }}</td>
                            <td class="px-2 py-2.5 text-center tabular-nums text-zinc-600 dark:text-white/70">{{ $row['played'] }}</td>
                            <td class="px-2 py-2.5 text-center tabular-nums text-zinc-600 dark:text-white/70">{{ $row['won'] }}</td>
                            <td class="px-2 py-2.5 text-center tabular-nums text-zinc-600 dark:text-white/70">{{ $row['drawn'] }}</td>
                            <td class="px-2 py-2.5 text-center tabular-nums text-zinc-600 dark:text-white/70">{{ $row['lost'] }}</td>
                            <td class="px-2 py-2.5 text-center tabular-nums text-zinc-600 dark:text-white/70">{{ $row['goals_for'] }}</td>
                            <td class="px-2 py-2.5 text-center tabular-nums text-zinc-600 dark:text-white/70">{{ $row['goals_against'] }}</td>
                            <td class="px-2 py-2.5 text-center tabular-nums text-zinc-600 dark:text-white/70">{{ $row['goal_difference'] }}</td>
                            <td class="px-4 py-2.5 text-center text-base font-bold tabular-nums text-zinc-900 dark:text-white">{{ $row['points'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile --}}
        <div class="divide-y divide-zinc-100 dark:divide-white/5 md:hidden">
            @foreach ($rows as $index => $row)
                <div class="flex items-center gap-3 px-4 py-3 {{ $index === 0 ? 'bg-accent/10' : '' }}">
                    <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $index === 0 ? 'bg-accent text-accent-foreground' : 'text-zinc-500 dark:text-white/50' }}">
                        {{ $index + 1 }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $row['team']->name }}</div>
                        <div class="mt-0.5 text-xs text-zinc-500 dark:text-white/50">
                            {{ __('PJ') }} {{ $row['played'] }} &middot; {{ __('DG') }} {{ $row['goal_difference'] }}
                        </div>
                    </div>

                    <div class="shrink-0 text-right">
                        <div class="text-lg font-bold tabular-nums text-zinc-900 dark:text-white">{{ $row['points'] }}</div>
                        <div class="text-[10px] uppercase tracking-wide text-zinc-400 dark:text-white/40">{{ __('pts') }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
