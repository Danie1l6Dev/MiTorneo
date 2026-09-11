<x-layouts::app :title="__('Editar equipo')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Editar equipo')" :subtitle="$team->category->name" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('teams.update', $team) }}" class="space-y-6">
                @csrf
                @method('PUT')

                @include('pages.teams._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                    <flux:button :href="route('categories.show', $team->category)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        </div>

        <flux:separator variant="subtle" />

        <x-ui.confirm-delete-form
            :action="route('teams.destroy', $team)"
            :heading="__('¿Eliminar este equipo?')"
            :description="__('Se eliminarán también su plantel y su historial de partidos. Esta acción no se puede deshacer.')"
            :confirm-label="__('Eliminar equipo')"
        >
            <flux:button variant="danger" icon="trash">{{ __('Eliminar equipo') }}</flux:button>
        </x-ui.confirm-delete-form>
    </div>
</x-layouts::app>
