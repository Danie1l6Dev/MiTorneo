@php
    // Marked players live in Alpine state ($picked) so "Marcar todos" is just
    // another write to it, instead of hunting the checkboxes in the DOM --
    // each one still carries its own name="player_ids[]" so the form submits
    // normally (an x-model binding alone submits nothing).
    $playerIds = $roster->pluck('id')->map(fn ($id): string => (string) $id)->values();
@endphp

<x-layouts::app :title="__('Mover jugadores')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header
            :title="__('Mover jugadores')"
            :subtitle="$team->name"
            :eyebrow="__('Categoría actual: :category', ['category' => $team->category->name])"
        >
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ...($team->club ? [['label' => $team->club->name, 'href' => route('clubs.show', $team->club)]] : []),
                    ['label' => $team->name, 'href' => route('teams.show', $team)],
                    ['label' => __('Mover jugadores')],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @if ($destinations->isEmpty())
            <x-ui.empty-state icon="user-group" :message="__('Este plantel no tiene otro plantel del mismo club al que mover jugadores.')" />
        @elseif ($roster->isEmpty())
            <x-ui.empty-state icon="user-group" :message="__('Todavía no hay jugadores en este plantel.')" />
        @else
            <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
                <form method="POST" action="{{ route('teams.players.move.store', $team) }}" class="space-y-6" x-data="{ picked: [] }">
                    @csrf

                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <flux:label>{{ __('Jugadores a mover') }}</flux:label>

                            <flux:checkbox
                                :label="__('Marcar todos')"
                                x-bind:checked="picked.length === {{ $playerIds->count() }}"
                                x-on:change="picked = $event.target.checked ? {{ Js::from($playerIds) }} : []"
                            />
                        </div>

                        <div class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-white/5 dark:border-white/10">
                            @foreach ($roster as $player)
                                <div class="flex items-center gap-3 px-4 py-2.5">
                                    <flux:checkbox name="player_ids[]" value="{{ $player->id }}" x-model="picked" />

                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">
                                            {{ $player->jersey_number !== null ? $player->jersey_number.' · ' : '' }}{{ $player->full_name }}
                                        </div>
                                        @unless ($player->is_active)
                                            <div class="text-xs text-zinc-500 dark:text-white/50">{{ __('Inactivo') }}</div>
                                        @endunless
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-3">
                        <flux:label>{{ __('Pasarlos a') }}</flux:label>

                        <flux:select name="destination_team_id" placeholder="{{ __('Elige el plantel de destino') }}" required>
                            @foreach ($destinations as $destination)
                                <flux:select.option value="{{ $destination->id }}">
                                    {{ $destination->category->name }} · {{ $destination->name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:text class="text-sm text-zinc-500 dark:text-white/50">
                            {{ __('Cada jugador marcado sale de :team y entra al plantel elegido. Los que ya estén ahí, o cuya fecha de nacimiento no permita esa categoría, se quedan donde están y se avisa al terminar.', ['team' => $team->name]) }}
                        </flux:text>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        {{-- :loading="false" because Flux reads [disabled] on
                             a submit button as "submitting" and would hide the
                             label behind a spinner that never stops -- here
                             disabled just means "nobody marked yet". --}}
                        <flux:button type="submit" variant="primary" icon="arrow-right-circle" :loading="false" x-bind:disabled="picked.length === 0">
                            {{ __('Mover seleccionados') }}
                        </flux:button>

                        <flux:button :href="route('teams.show', $team)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-layouts::app>
