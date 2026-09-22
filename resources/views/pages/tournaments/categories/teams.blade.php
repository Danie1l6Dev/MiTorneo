@php
    $teamsByGroup = $teams->groupBy(fn ($team) => $team->group?->name ?? __('Sin grupo'));
@endphp

<x-layouts::app :title="__('Planteles de :category', ['category' => $category->name])">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Planteles de :category', ['category' => $category->name])" :subtitle="$tournament->name" />

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @if ($locked)
            <flux:callout variant="warning" icon="lock-closed" :heading="__('Planteles bloqueados')">
                {{ __('Esta categoría ya tiene una fase iniciada en este torneo -- el plantel ya quedó fijado para poder armar el calendario/cuadro sobre él, y no se puede modificar desde aquí.') }}
            </flux:callout>
        @endif

        @if ($teams->isEmpty())
            <x-ui.empty-state icon="user-group" :message="__('Todavía no hay ningún club con plantel en esta categoría.')">
                <x-slot:action>
                    <flux:button :href="route('clubs.index')" variant="primary" size="sm" wire:navigate>
                        {{ __('Ir a Clubes') }}
                    </flux:button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
                <form method="POST" action="{{ route('tournaments.global-categories.teams.update', [$tournament, $category]) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="space-y-4">
                        <flux:label>{{ __('Elige qué planteles participan en este torneo') }}</flux:label>

                        @foreach ($teamsByGroup as $groupName => $groupTeams)
                            <div class="space-y-1 rounded-xl border border-zinc-200 p-3 dark:border-white/10">
                                @if ($teamsByGroup->count() > 1)
                                    <div class="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">
                                        {{ $groupName }}
                                    </div>
                                @endif

                                @foreach ($groupTeams as $team)
                                    <flux:checkbox
                                        name="team_ids[]"
                                        value="{{ $team->id }}"
                                        label="{{ $team->club->name }}{{ $team->club->name !== $team->name ? ' — '.$team->name : '' }}"
                                        :checked="in_array($team->id, old('team_ids', $selectedIds))"
                                        :disabled="$locked"
                                    />
                                @endforeach
                            </div>
                        @endforeach

                        @error('team_ids')
                            <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                        @enderror
                    </div>

                    <div class="flex items-center gap-3">
                        @unless ($locked)
                            <flux:button type="submit" variant="primary">{{ __('Guardar planteles') }}</flux:button>
                        @endunless
                        <flux:button :href="route('tournaments.categories.show', [$tournament, $category])" variant="ghost" wire:navigate>{{ $locked ? __('Volver') : __('Cancelar') }}</flux:button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-layouts::app>
