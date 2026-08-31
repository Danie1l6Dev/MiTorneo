<x-layouts::app :title="__('Editar equipo')">
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Editar equipo') }}</flux:heading>
            <flux:text class="mt-1">{{ $team->category->name }}</flux:text>
        </div>

        <form method="POST" action="{{ route('teams.update', $team) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @include('pages.teams._fields')

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                <flux:button :href="route('categories.show', $team->category)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
            </div>
        </form>

        <flux:separator />

        <form method="POST" action="{{ route('teams.destroy', $team) }}" onsubmit="return confirm('{{ __('¿Eliminar este equipo?') }}')">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar equipo') }}</flux:button>
        </form>
    </div>
</x-layouts::app>
