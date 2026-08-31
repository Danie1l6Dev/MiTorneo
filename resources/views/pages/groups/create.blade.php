<x-layouts::app :title="__('Nuevo grupo')">
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Nuevo grupo') }}</flux:heading>
            <flux:text class="mt-1">{{ $phase->name }}</flux:text>
        </div>

        <form method="POST" action="{{ route('phases.groups.store', $phase) }}" class="space-y-6">
            @csrf

            @include('pages.groups._fields')

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Crear grupo') }}</flux:button>
                <flux:button :href="route('phases.show', $phase)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
