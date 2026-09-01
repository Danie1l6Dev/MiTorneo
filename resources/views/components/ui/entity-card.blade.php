@props([
    'href' => null,
    'title' => null,
    'icon' => null,
    'color' => 'accent',
    'stats' => [],
    'cta' => null,
    'size' => 'default',
])

@php
    $tag = $href ? 'a' : 'div';
    $isLarge = $size === 'lg';

    $iconWrap = match ($color) {
        'green' => 'bg-green-500/15 text-green-400',
        'cyan' => 'bg-cyan-500/15 text-cyan-400',
        'amber' => 'bg-amber-500/15 text-amber-400',
        'red' => 'bg-red-500/15 text-red-400',
        default => 'bg-accent-content/15 text-accent-content',
    };

    $classes = 'hover-lift group block w-full rounded-3xl border border-zinc-200 bg-white dark:border-white/10 glass-panel'
        . ($isLarge
            ? ' sm:w-[calc(50%-0.5rem)] lg:w-[calc(33.333%-0.667rem)] lg:max-w-md p-8'
            : ' sm:w-[calc(50%-0.5rem)] lg:w-[calc(33.333%-0.667rem)] lg:max-w-sm p-7')
        . ($href ? ' cursor-pointer' : '');

    $iconBoxSize = $isLarge ? 'size-14' : 'size-11';
    $iconGlyphSize = $isLarge ? 'size-7' : 'size-5';
    $titleSizeClass = $isLarge ? 'text-2xl!' : 'text-xl!';
    $titleWrapClass = $isLarge ? 'line-clamp-2' : 'truncate';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class($classes) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            @if ($icon)
                <div class="flex {{ $iconBoxSize }} shrink-0 items-center justify-center rounded-2xl {{ $iconWrap }}">
                    <flux:icon :icon="$icon" variant="micro" class="{{ $iconGlyphSize }}" />
                </div>
            @endif

            <flux:heading size="lg" class="{{ $titleWrapClass }} {{ $titleSizeClass }}">{{ $title }}</flux:heading>
        </div>

        @isset($badges)
            <div class="flex shrink-0 flex-wrap items-center gap-1.5">{{ $badges }}</div>
        @endisset
    </div>

    @isset($description)
        <flux:text class="mt-2 line-clamp-2 {{ $isLarge ? 'text-base' : '' }}">{{ $description }}</flux:text>
    @endisset

    @if (count($stats))
        <div class="mt-5 flex flex-wrap items-center gap-x-2 gap-y-1 {{ $isLarge ? 'text-base' : 'text-sm' }} text-zinc-500 dark:text-white/60">
            @foreach ($stats as $stat)
                <span>{{ $stat }}</span>

                @if (!$loop->last)
                    <span class="text-zinc-300 dark:text-white/20">&middot;</span>
                @endif
            @endforeach
        </div>
    @endif

    @if ($href)
        <div class="mt-5 flex items-center gap-1 text-sm font-medium text-accent-content opacity-0 transition-opacity group-hover:opacity-100">
            {{ $cta ?? __('Ver más') }}
            <flux:icon.arrow-right class="size-3.5" />
        </div>
    @endif
</{{ $tag }}>
