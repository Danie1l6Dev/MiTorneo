@props(['team'])

<div {{ $attributes->class('flex items-center justify-between gap-3 px-4 py-3') }}>
    <div class="flex min-w-0 items-center gap-3">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-content/15 text-xs font-bold uppercase text-accent-content">
            {{ \Illuminate\Support\Str::substr($team->short_name ?: $team->name, 0, 2) }}
        </div>

        <div class="min-w-0">
            <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $team->name }}</div>

            @if ($team->short_name)
                <div class="truncate text-xs text-zinc-500 dark:text-white/50">{{ $team->short_name }}</div>
            @endif
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-1">
        {{ $actions ?? '' }}

        <flux:button :href="route('teams.edit', $team)" variant="ghost" size="sm" icon="pencil" wire:navigate />
    </div>
</div>
