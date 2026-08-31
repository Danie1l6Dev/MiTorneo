<x-layouts::app :title="__('Nueva categoría')">
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Nueva categoría') }}</flux:heading>
            <flux:text class="mt-1">{{ $tournament->name }}</flux:text>
        </div>

        <form method="POST" action="{{ route('tournaments.categories.store', $tournament) }}" class="space-y-6">
            @csrf

            @include('pages.categories._fields')

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Crear categoría') }}</flux:button>
                <flux:button :href="route('tournaments.show', $tournament)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
