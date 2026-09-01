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

<div {{ $attributes->class('hover-lift relative overflow-hidden rounded-3xl border border-zinc-200 bg-white p-6 text-center dark:border-white/10 glass-panel sm:p-8') }}>
    @if ($icon)
        <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-2xl {{ $iconWrap }}">
            <flux:icon :icon="$icon" variant="micro" class="size-6" />
        </div>
    @endif

    <div class="font-display text-4xl font-bold tabular-nums text-zinc-900 dark:text-white sm:text-5xl">{{ $value }}</div>

    <div class="mt-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/60">{{ $label }}</div>

    @if ($hint)
        <div class="mt-1 text-xs text-zinc-400 dark:text-white/50">{{ $hint }}</div>
    @endif
</div>
