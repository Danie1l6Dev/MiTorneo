@props([
    'title' => null,
    'subtitle' => null,
    'eyebrow' => null,
])

<div {{ $attributes->class('flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between') }}>
    <div class="min-w-0 space-y-1.5">
        @isset($breadcrumbs)
            <div class="mb-1">{{ $breadcrumbs }}</div>
        @endisset

        @if ($eyebrow)
            <div class="text-xs font-semibold uppercase tracking-widest text-accent-content">{{ $eyebrow }}</div>
        @endif

        @if ($title)
            <flux:heading size="xl" class="text-2xl sm:text-3xl!">{{ $title }}</flux:heading>
        @endif

        @if ($subtitle)
            <flux:text class="max-w-2xl">{{ $subtitle }}</flux:text>
        @endif

        {{ $slot }}
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
