@props([
    'match' => null,
    'resting' => null,
    'href' => null,
])

@if ($resting)
    <div {{ $attributes->class('flex flex-col items-center justify-center gap-1.5 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/60 px-4 py-6 text-center dark:border-white/15 dark:bg-white/[0.03]') }}>
        <flux:icon.moon variant="outline" class="size-4 text-zinc-400 dark:text-white/40" />
        <div class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-white/40">{{ __('DESCANSA') }}</div>
        <div class="text-sm font-medium text-zinc-600 dark:text-white/70">{{ $resting }}</div>
    </div>
@else
    @php
        $finished = $match->status === \App\Enums\MatchStatus::Finished;
        $tag = $href ? 'a' : 'div';
        $scoreClasses = $finished ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 dark:text-white/40';
    @endphp

    <{{ $tag }}
        @if ($href) href="{{ $href }}" wire:navigate @endif
        {{ $attributes->class('hover-lift group block rounded-2xl border border-zinc-200 bg-white p-4 dark:border-white/10 glass-panel' . ($href ? ' cursor-pointer' : '')) }}
    >
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0 flex-1 text-right text-sm font-semibold truncate text-zinc-800 dark:text-white">
                {{ $match->homeTeam->name }}
            </div>

            <div class="flex shrink-0 items-center gap-2 rounded-xl bg-zinc-100 px-3 py-1.5 dark:bg-white/10">
                <span class="font-display text-lg font-bold tabular-nums {{ $scoreClasses }}">{{ $match->home_score ?? '–' }}</span>
                <span class="text-zinc-400 dark:text-white/30">-</span>
                <span class="font-display text-lg font-bold tabular-nums {{ $scoreClasses }}">{{ $match->away_score ?? '–' }}</span>
            </div>

            <div class="min-w-0 flex-1 text-left text-sm font-semibold truncate text-zinc-800 dark:text-white">
                {{ $match->awayTeam->name }}
            </div>
        </div>

        <div class="mt-3 flex items-center justify-center">
            <flux:badge size="sm" :color="$match->status->color()">{{ mb_strtoupper($match->status->label()) }}</flux:badge>
        </div>
    </{{ $tag }}>
@endif
