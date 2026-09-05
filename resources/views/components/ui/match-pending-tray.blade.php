@props([
    'teamId',
])

{{--
    Its own card, stacked below x-ui.match-roster-panel in the same column --
    deliberately NOT appended inside that panel, so the roster list itself
    never grows/shifts as events get queued. Reads off the same shared Alpine
    scope as the roster panel (pendingForTeam, remove) via DOM nesting, not a
    Blade prop.
--}}
<div x-show="pendingForTeam({{ $teamId }}).length > 0" x-cloak class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
    <div class="border-b border-zinc-200 px-4 py-2 dark:border-white/10">
        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
            {{ __('Por guardar') }}
        </span>
    </div>

    <div class="divide-y divide-zinc-100 dark:divide-white/5">
        <template x-for="entry in pendingForTeam({{ $teamId }})" :key="entry.item.uid">
            <div class="flex items-center justify-between gap-2 px-4 py-2">
                <div class="flex min-w-0 items-center gap-2">
                    {{-- The icon depends on entry.item.type at runtime, so
                         every possible one is rendered and toggled with
                         x-show rather than picked from JS -- there's no SVG
                         markup embedded in Alpine state anywhere. --}}
                    <x-tabler-ball-football x-show="entry.item.type === 'goal'" x-cloak class="size-4 shrink-0 text-green-500" />
                    <x-tabler-shoe x-show="entry.item.type === 'assist'" x-cloak class="size-4 shrink-0 text-cyan-500" />
                    <x-tabler-rectangle-vertical-filled x-show="entry.item.type === 'yellow_card'" x-cloak class="size-4 shrink-0 text-amber-500" />
                    <x-tabler-rectangle-vertical-filled x-show="entry.item.type === 'red_card'" x-cloak class="size-4 shrink-0 text-red-500" />
                    <span
                        class="truncate text-sm text-zinc-700 dark:text-white/80"
                        x-text="entry.item.count > 1 ? entry.item.count + 'x ' + entry.item.label : entry.item.label"
                    ></span>
                    <span
                        x-show="entry.item.note"
                        x-cloak
                        x-text="entry.item.note"
                        class="shrink-0 rounded-full bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-amber-500"
                    ></span>
                </div>

                <button
                    type="button"
                    @click="remove(entry.index)"
                    class="shrink-0 rounded-md px-1 py-0.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-white"
                >✕</button>
            </div>
        </template>
    </div>
</div>
