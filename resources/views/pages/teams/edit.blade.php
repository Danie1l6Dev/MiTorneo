<x-layouts::app :title="__('Editar equipo')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Editar equipo')" :subtitle="$team->category->name" />

        <div class="rounded-xl border border-zinc-200 p-5 dark:border-white/10 glass-panel sm:p-6">
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

        <form method="POST" action="{{ route('teams.destroy', $team) }}" onsubmit="return confirm('{{ __('¿Eliminar este equipo?') }}')">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar equipo') }}</flux:button>
        </form>
    </div>
</x-layouts::app>
