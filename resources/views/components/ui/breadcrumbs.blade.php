@props(['items' => []])

<nav {{ $attributes->class('flex items-center gap-1.5 text-xs text-zinc-500 dark:text-white/60') }} aria-label="Breadcrumb">
    @foreach ($items as $item)
        @if (!$loop->first)
            <flux:icon.chevron-right class="size-3 shrink-0 text-zinc-400 dark:text-white/30" />
        @endif

        @if (!empty($item['href']) && !$loop->last)
            <a href="{{ $item['href'] }}" wire:navigate class="truncate transition-colors hover:text-zinc-800 dark:hover:text-white">
                {{ $item['label'] }}
            </a>
        @else
            <span class="truncate font-medium text-zinc-700 dark:text-white/85">{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>
