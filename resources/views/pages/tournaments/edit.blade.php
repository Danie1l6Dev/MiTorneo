<x-layouts::app :title="__('Editar torneo')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Editar torneo')" :subtitle="$tournament->name" />

        <div class="rounded-xl border border-zinc-200 p-5 dark:border-white/10 glass-panel sm:p-6">
            <form method="POST" action="{{ route('tournaments.update', $tournament) }}" class="space-y-6">
                @csrf
                @method('PUT')

                @include('pages.tournaments._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                    <flux:button :href="route('tournaments.show', $tournament)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
