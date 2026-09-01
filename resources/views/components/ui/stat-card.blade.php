@props([
    'label' => null,
    'value' => null,
    'icon' => null,
    'color' => 'accent',
    'hint' => null,
])

@php
    $iconWrap = match ($color) {
        'green' => 'bg-green-500/15 text-green-400',
        'cyan' => 'bg-cyan-500/15 text-cyan-400',
        'amber' => 'bg-amber-500/15 text-amber-400',
        'red' => 'bg-red-500/15 text-red-400',
        default => 'bg-accent-content/15 text-accent-content',
    };
@endphp

<div {{ $attributes->class('hover-lift relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 glass-panel') }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="truncate text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/60">{{ $label }}</div>
            <div class="mt-1.5 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div>

            @if ($hint)
                <div class="mt-1 text-xs text-zinc-400 dark:text-white/50">{{ $hint }}</div>
            @endif
        </div>

        @if ($icon)
            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg {{ $iconWrap }}">
                <flux:icon :icon="$icon" variant="micro" class="size-5" />
            </div>
        @endif
    </div>
</div>
