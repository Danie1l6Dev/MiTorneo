<x-layouts::app :title="__('Editar fase')">
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Editar fase de competición') }}</flux:heading>
            <flux:text class="mt-1">{{ $phase->category->name }}</flux:text>
        </div>

        <form method="POST" action="{{ route('phases.update', $phase) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @include('pages.phases._fields')

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                <flux:button :href="route('phases.show', $phase)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
