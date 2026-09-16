@props([
    'team',
    'expelled' => false,
    // teams.edit's own "Cancelar"/delete flow always lands back on
    // categories.show($team->category) -- the global catalog page -- since
    // that's the one place a plantel's own fields (name/short_name) always
    // belong, regardless of which tournament it's entered in. That's the
    // right "home" to return to from the catalog's own team list (where
    // this component defaults to editable) or a group's roster, but from a
    // tournament's own "Equipos inscritos" list it silently drops the
    // organizer out of the tournament they were just looking at and into
    // the catalog instead -- confusing enough that that caller passes false
    // to hide the pencil button entirely rather than editing from there.
    'editable' => true,
])

<div {{ $attributes->class('flex items-center justify-between gap-3 px-4 py-3') }}>
    <a href="{{ route('teams.show', $team) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-content/15 text-xs font-bold uppercase text-accent-content">
            {{ \Illuminate\Support\Str::substr($team->short_name ?: $team->name, 0, 2) }}
        </div>

        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $team->name }}</div>

                @if ($expelled)
                    <flux:badge size="sm" color="red">{{ __('Expulsado') }}</flux:badge>
                @endif
            </div>

            @if ($team->short_name)
                <div class="truncate text-xs text-zinc-500 dark:text-white/50">{{ $team->short_name }}</div>
            @endif
        </div>
    </a>

    <div class="flex shrink-0 items-center gap-1">
        {{ $actions ?? '' }}

        @if ($editable)
            <flux:button :href="route('teams.edit', $team)" variant="ghost" size="sm" icon="pencil" wire:navigate />
        @endif
    </div>
</div>
