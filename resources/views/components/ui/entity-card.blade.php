@props([
    'href' => null,
    'title' => null,
    'stats' => [],
    'cta' => null,
])

@php
    $tag = $href ? 'a' : 'div';

    $classes = 'hover-lift group block h-full rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 glass-panel'
        . ($href ? ' cursor-pointer' : '');
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class($classes) }}>
    <div class="flex items-start justify-between gap-3">
        <flux:heading size="sm" class="truncate">{{ $title }}</flux:heading>

        @isset($badges)
            <div class="flex shrink-0 flex-wrap items-center gap-1.5">{{ $badges }}</div>
        @endisset
    </div>

    @isset($description)
        <flux:text class="mt-1.5 line-clamp-2 text-sm">{{ $description }}</flux:text>
    @endisset

    @if (count($stats))
        <div class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-white/60">
            @foreach ($stats as $stat)
                <span>{{ $stat }}</span>

                @if (!$loop->last)
                    <span class="text-zinc-300 dark:text-white/20">&middot;</span>
                @endif
            @endforeach
        </div>
    @endif

    @if ($href)
        <div class="mt-4 flex items-center gap-1 text-sm font-medium text-accent-content opacity-0 transition-opacity group-hover:opacity-100">
            {{ $cta ?? __('Ver más') }}
            <flux:icon.arrow-right class="size-3.5" />
        </div>
    @endif
</{{ $tag }}>
