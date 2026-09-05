@props([
    'team',
    'players',
])

{{--
    No <form>/x-data of its own: every button here calls a method on the
    ancestor scope declared in pages/matches/edit.blade.php (addGoal,
    addAssist, addYellow, addRed, isExpelled, countFor) -- Alpine scoping
    follows final DOM nesting, not Blade component boundaries, so this works
    even though the component is a separate file. Clicks only push into that
    shared in-memory queue; nothing is saved until the page's single
    "Guardar eventos" submit, so registering several events in a row no
    longer reloads the page each time.
--}}
<div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
    <div class="border-b border-zinc-200 px-4 py-2.5 dark:border-white/10">
        <flux:heading size="sm" class="truncate">{{ $team->name }}</flux:heading>
    </div>

    {{-- The coach can be shown cards too (amonestación/expulsión), just
         never a goal or assist -- only 2 buttons here, and addYellow/addRed
         are called with subjectType 'coach' instead of 'player'. Nothing
         renders if the team has no active DT registered. --}}
    @if ($team->coach)
        @php $coachLabel = \Illuminate\Support\Js::from(__('DT').': '.$team->coach->full_name); @endphp

        <div class="flex items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2.5 dark:border-white/10 dark:bg-white/[0.03]">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-baseline gap-x-1.5">
                    <span class="shrink-0 rounded bg-accent-content/15 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-accent-content">{{ __('DT') }}</span>
                    <span class="text-sm font-medium break-words text-zinc-700 dark:text-white/80">{{ $team->coach->full_name }}</span>
                </div>

                <div class="mt-0.5 flex items-center gap-0.5" x-show="countFor('coach', {{ $team->coach->id }}) > 0 || isExpelled('coach', {{ $team->coach->id }})" x-cloak>
                    <template x-for="n in Math.min(countFor('coach', {{ $team->coach->id }}), 2)" :key="n">
                        <x-tabler-rectangle-vertical-filled class="size-3 text-amber-500" />
                    </template>
                    <x-tabler-rectangle-vertical-filled x-show="isExpelled('coach', {{ $team->coach->id }})" class="size-3 text-red-500" />
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-0.5">
                <flux:tooltip :content="__('Amarilla (marcarla 2 veces = expulsión)')">
                    <button
                        type="button"
                        x-bind:disabled="isExpelled('coach', {{ $team->coach->id }})"
                        @click="addYellow('coach', {{ $team->coach->id }}, {{ $team->id }}, {{ $coachLabel }})"
                        class="rounded-md p-1.5 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-white/10"
                    ><x-tabler-rectangle-vertical-filled class="size-4 text-amber-500" /></button>
                </flux:tooltip>

                <flux:tooltip :content="__('Roja directa')">
                    <button
                        type="button"
                        x-bind:disabled="isExpelled('coach', {{ $team->coach->id }})"
                        @click="addRed('coach', {{ $team->coach->id }}, {{ $team->id }}, {{ $coachLabel }})"
                        class="rounded-md p-1.5 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-white/10"
                    ><x-tabler-rectangle-vertical-filled class="size-4 text-red-500" /></button>
                </flux:tooltip>
            </div>
        </div>
    @endif

    @if ($players->isEmpty())
        <div class="px-4 py-4 text-sm text-zinc-400 dark:text-white/40">{{ __('Sin jugadores registrados.') }}</div>
    @else
        <div class="max-h-[32rem] divide-y divide-zinc-100 overflow-y-auto dark:divide-white/5">
            @foreach ($players as $player)
                @php $jsName = \Illuminate\Support\Js::from($player->full_name); @endphp

                <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-1.5">
                            <span class="shrink-0 font-display text-sm font-bold tabular-nums text-zinc-500 dark:text-white/50">#{{ $player->jersey_number }}</span>
                            <span class="text-sm font-medium break-words text-zinc-700 dark:text-white/80">{{ $player->full_name }}</span>
                        </div>

                        <div class="mt-0.5 flex items-center gap-0.5" x-show="countFor('player', {{ $player->id }}) > 0 || isExpelled('player', {{ $player->id }})" x-cloak>
                            <template x-for="n in Math.min(countFor('player', {{ $player->id }}), 2)" :key="n">
                                <x-tabler-rectangle-vertical-filled class="size-3 text-amber-500" />
                            </template>
                            <x-tabler-rectangle-vertical-filled x-show="isExpelled('player', {{ $player->id }})" class="size-3 text-red-500" />
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-0.5">
                        <flux:tooltip :content="__('Gol')">
                            <button
                                type="button"
                                @click="addGoal({{ $player->id }}, {{ $team->id }}, {{ $jsName }})"
                                class="rounded-md p-1.5 hover:bg-zinc-100 dark:hover:bg-white/10"
                            ><x-tabler-ball-football class="size-4 text-green-500" /></button>
                        </flux:tooltip>

                        <flux:tooltip :content="__('Asistencia')">
                            <button
                                type="button"
                                @click="addAssist({{ $player->id }}, {{ $team->id }}, {{ $jsName }})"
                                class="rounded-md p-1.5 hover:bg-zinc-100 dark:hover:bg-white/10"
                            ><x-tabler-shoe class="size-4 text-cyan-500" /></button>
                        </flux:tooltip>

                        <flux:tooltip :content="__('Amarilla (marcarla 2 veces = expulsión)')">
                            <button
                                type="button"
                                x-bind:disabled="isExpelled('player', {{ $player->id }})"
                                @click="addYellow('player', {{ $player->id }}, {{ $team->id }}, {{ $jsName }})"
                                class="rounded-md p-1.5 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-white/10"
                            ><x-tabler-rectangle-vertical-filled class="size-4 text-amber-500" /></button>
                        </flux:tooltip>

                        <flux:tooltip :content="__('Roja directa')">
                            <button
                                type="button"
                                x-bind:disabled="isExpelled('player', {{ $player->id }})"
                                @click="addRed('player', {{ $player->id }}, {{ $team->id }}, {{ $jsName }})"
                                class="rounded-md p-1.5 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-white/10"
                            ><x-tabler-rectangle-vertical-filled class="size-4 text-red-500" /></button>
                        </flux:tooltip>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
