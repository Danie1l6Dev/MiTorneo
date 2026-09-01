<x-layouts::app :title="__('Editar partido')">
    <div class="mx-auto w-full max-w-2xl space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Editar partido')" :subtitle="$match->competitionPhase->name" />

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
            <div class="flex items-center justify-center border-b border-zinc-200 px-4 py-2.5 dark:border-white/10">
                <flux:badge size="sm" :color="$match->status->color()">{{ mb_strtoupper($match->status->label()) }}</flux:badge>
            </div>

            <div class="grid grid-cols-3 items-center gap-3 px-4 py-8 sm:px-8">
                <div class="text-center">
                    <div class="mx-auto mb-2 flex size-14 items-center justify-center rounded-full bg-accent-content/15 text-lg font-bold uppercase text-accent-content sm:size-16">
                        {{ \Illuminate\Support\Str::substr($match->homeTeam->short_name ?: $match->homeTeam->name, 0, 2) }}
                    </div>
                    <flux:heading size="sm" class="truncate">{{ $match->homeTeam->name }}</flux:heading>
                </div>

                <div class="flex items-center justify-center gap-2 sm:gap-4">
                    <span class="font-display text-5xl font-bold tabular-nums text-zinc-900 dark:text-white sm:text-6xl">{{ $match->home_score ?? '–' }}</span>
                    <span class="text-2xl font-light text-zinc-300 dark:text-white/25">:</span>
                    <span class="font-display text-5xl font-bold tabular-nums text-zinc-900 dark:text-white sm:text-6xl">{{ $match->away_score ?? '–' }}</span>
                </div>

                <div class="text-center">
                    <div class="mx-auto mb-2 flex size-14 items-center justify-center rounded-full bg-accent-content/15 text-lg font-bold uppercase text-accent-content sm:size-16">
                        {{ \Illuminate\Support\Str::substr($match->awayTeam->short_name ?: $match->awayTeam->name, 0, 2) }}
                    </div>
                    <flux:heading size="sm" class="truncate">{{ $match->awayTeam->name }}</flux:heading>
                </div>
            </div>

            <form method="POST" action="{{ route('matches.result.update', $match) }}" class="flex flex-wrap items-end justify-center gap-4 border-t border-zinc-200 px-4 py-6 dark:border-white/10 sm:px-8">
                @csrf
                @method('PATCH')

                <flux:input
                    name="home_score"
                    type="number"
                    min="0"
                    label="{{ $match->homeTeam->name }}"
                    value="{{ old('home_score', $match->home_score ?? '') }}"
                    class="w-24"
                />

                <div class="pb-2.5 text-lg text-zinc-400 dark:text-white/30">&ndash;</div>

                <flux:input
                    name="away_score"
                    type="number"
                    min="0"
                    label="{{ $match->awayTeam->name }}"
                    value="{{ old('away_score', $match->away_score ?? '') }}"
                    class="w-24"
                />

                <flux:button type="submit" variant="primary" icon="check">{{ __('Registrar resultado') }}</flux:button>
            </form>
        </div>

        <flux:separator variant="subtle" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <flux:heading size="sm" class="mb-4">{{ __('Detalles del partido') }}</flux:heading>

            <form method="POST" action="{{ route('matches.update', $match) }}" class="space-y-6">
                @csrf
                @method('PUT')

                @include('pages.matches._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                    <flux:button :href="route('phases.show', $match->competitionPhase)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        </div>

        <flux:separator variant="subtle" />

        <form method="POST" action="{{ route('matches.destroy', $match) }}" onsubmit="return confirm('{{ __('¿Eliminar este partido?') }}')">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar partido') }}</flux:button>
        </form>
    </div>
</x-layouts::app>
