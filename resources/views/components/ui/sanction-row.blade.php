@props(['sanction'])

<a href="{{ route('sanctions.show', $sanction) }}" wire:navigate class="flex items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-zinc-50 dark:hover:bg-white/5">
    <div class="min-w-0">
        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $sanction->subjectLabel() }}</div>
        <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-white/50">
            {{ $sanction->team->name }} &middot; {{ $sanction->team->tournament->name }} &middot; {{ $sanction->match->category->name }}
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-2">
        <flux:badge size="sm" :color="$sanction->type->color()">{{ $sanction->type->label() }}</flux:badge>
        <flux:badge size="sm" :color="$sanction->status->color()">{{ $sanction->stateLabel() }}</flux:badge>
    </div>
</a>
