@props(['tournament', 'sanction'])

{{--
    Read-only counterpart to x-ui.sanction-row: same badges, but links into
    the subject's own public team page instead of sanctions.show (an
    auth-only admin route the public portal never exposes).
--}}
<div class="flex items-center justify-between gap-3 px-4 py-3">
    <div class="min-w-0">
        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $sanction->subjectLabel() }}</div>
        <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-white/50">
            <flux:link :href="route('public.tournaments.teams.show', [$tournament, $sanction->team])" wire:navigate class="text-xs">
                {{ $sanction->team->name }}
            </flux:link>
            &middot; {{ $sanction->match->category->name }}
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-2">
        <flux:badge size="sm" :color="$sanction->type->color()">{{ $sanction->type->label() }}</flux:badge>
        <flux:badge size="sm" :color="$sanction->status->color()">{{ $sanction->stateLabel() }}</flux:badge>
    </div>
</div>
