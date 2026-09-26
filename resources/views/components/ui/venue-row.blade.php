@props([
    'venue',
])

<div {{ $attributes->class('flex items-center justify-between gap-3 px-4 py-3') }}>
    <a href="{{ route('venues.edit', $venue) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 rounded-lg -m-1 p-1 transition-colors hover:bg-zinc-50 dark:hover:bg-white/5">
        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent-content/15 text-accent-content">
            <flux:icon.map-pin variant="micro" class="size-5" />
        </div>

        <div class="min-w-0 truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $venue->name }}</div>
    </a>

    <div class="flex shrink-0 items-center gap-3">
        <div class="text-right">
            <div class="font-display text-base font-bold tabular-nums text-zinc-900 dark:text-white">{{ $venue->matches_count }}</div>
            <div class="text-[10px] uppercase tracking-wide text-zinc-400 dark:text-white/40">{{ trans_choice('partido|partidos', $venue->matches_count) }}</div>
        </div>

        <flux:tooltip :content="__('Editar')">
            <flux:button :href="route('venues.edit', $venue)" variant="ghost" size="sm" icon="pencil" wire:navigate />
        </flux:tooltip>
    </div>
</div>
