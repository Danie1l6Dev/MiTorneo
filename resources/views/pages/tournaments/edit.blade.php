<x-layouts::app :title="__('Editar torneo')">
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Editar torneo') }}</flux:heading>
            <flux:text class="mt-1">{{ $tournament->name }}</flux:text>
        </div>

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
</x-layouts::app>
