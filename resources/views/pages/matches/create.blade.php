<x-layouts::app :title="__('Nuevo partido')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Nuevo partido')" :subtitle="$phase->name" />

        @if ($teams->count() < 2)
            <x-ui.empty-state icon="user-group" :message="__('La categoría necesita al menos dos equipos registrados para poder cargar un partido.')">
                <x-slot:action>
                    <flux:button :href="route('phases.show', $phase)" variant="ghost" wire:navigate>{{ __('Volver') }}</flux:button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-white/10 glass-panel sm:p-6">
                <form method="POST" action="{{ route('phases.matches.store', $phase) }}" class="space-y-6">
                    @csrf

                    @include('pages.matches._fields')

                    <div class="flex items-center gap-3">
                        <flux:button type="submit" variant="primary">{{ __('Crear partido') }}</flux:button>
                        <flux:button :href="route('phases.show', $phase)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-layouts::app>
