<x-layouts::app :title="__('Nuevo torneo')">
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Nuevo torneo') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Crea un torneo para empezar a organizar sus categorías, fases y partidos.') }}</flux:text>
        </div>

        <form method="POST" action="{{ route('tournaments.store') }}" class="space-y-6">
            @csrf

            @include('pages.tournaments._fields')

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Crear torneo') }}</flux:button>
                <flux:button :href="route('tournaments.index')" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
