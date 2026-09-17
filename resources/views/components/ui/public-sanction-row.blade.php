@props(['tournament', 'sanction'])

{{--
    Read-only counterpart to x-ui.sanction-row. "Ver resolución" is a real
    flux:button (not just a badge/link) so it's a proper tap target on
    mobile, instead of relying on the whole row being clickable. Always the
    same label/style regardless of whether the sanction is actually
    resolved yet -- the destination page itself already shows that state.
--}}
<div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
    <div class="min-w-0">
        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $sanction->subjectLabel() }}</div>
        <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-white/50">
            {{ $sanction->team->name }} &middot; {{ $sanction->match->category->name }}
        </div>

        <div class="mt-1.5 flex flex-wrap items-center gap-2">
            <flux:badge size="sm" :color="$sanction->type->color()">{{ $sanction->type->label() }}</flux:badge>
            <flux:badge size="sm" :color="$sanction->status->color()">{{ $sanction->stateLabel() }}</flux:badge>
        </div>
    </div>

    <flux:button :href="route('public.tournaments.sanctions.show', [$tournament, $sanction])" wire:navigate size="sm" variant="primary" class="shrink-0">
        {{ __('Ver resolución') }}
    </flux:button>
</div>
