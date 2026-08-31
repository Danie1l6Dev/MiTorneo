<x-layouts::app :title="__('Nuevo partido')">
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Nuevo partido') }}</flux:heading>
            <flux:text class="mt-1">{{ $phase->name }}</flux:text>
        </div>

        @if ($teams->count() < 2)
            <flux:text class="text-zinc-500">
                {{ __('La categoría necesita al menos dos equipos registrados para poder cargar un partido.') }}
            </flux:text>

            <flux:button :href="route('phases.show', $phase)" variant="ghost" wire:navigate>{{ __('Volver') }}</flux:button>
        @else
            <form method="POST" action="{{ route('phases.matches.store', $phase) }}" class="space-y-6">
                @csrf

                @include('pages.matches._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Crear partido') }}</flux:button>
                    <flux:button :href="route('phases.show', $phase)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        @endif
    </div>
</x-layouts::app>
