<x-layouts::app :title="__('Editar partido')">
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Editar partido') }}</flux:heading>
            <flux:text class="mt-1">{{ $match->competitionPhase->name }}</flux:text>
        </div>

        <form method="POST" action="{{ route('matches.update', $match) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @include('pages.matches._fields')

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                <flux:button :href="route('phases.show', $match->competitionPhase)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
            </div>
        </form>

        <flux:separator />

        <form method="POST" action="{{ route('matches.destroy', $match) }}" onsubmit="return confirm('{{ __('¿Eliminar este partido?') }}')">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar partido') }}</flux:button>
        </form>
    </div>
</x-layouts::app>
