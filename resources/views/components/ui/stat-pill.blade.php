@props([
    'icon' => null,
    'value' => null,
    'label' => null,
    'color' => 'accent',
])

@php
    $iconColor = match ($color) {
        'green' => 'text-green-400',
        'cyan' => 'text-cyan-400',
        'amber' => 'text-amber-400',
        'red' => 'text-red-400',
        default => 'text-accent-content',
    };
@endphp

<div {{ $attributes->class('inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-3.5 py-1.5 text-sm dark:border-white/10 glass-panel') }}>
    @if ($icon)
        <flux:icon :icon="$icon" variant="micro" class="size-4 shrink-0 {{ $iconColor }}" />
    @endif

    <span class="font-display font-semibold text-zinc-900 dark:text-white">{{ $value }}</span>
    <span class="text-zinc-500 dark:text-white/60">{{ $label }}</span>
</div>
