<x-layouts::app :title="__('Editar cancha')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Editar cancha')" :subtitle="$venue->name">
            <x-slot:actions>
                <x-ui.confirm-delete-form
                    :action="route('venues.destroy', $venue)"
                    :heading="__('¿Eliminar esta cancha?')"
                    :description="$venue->matches_count > 0
                        ? trans_choice('Tiene :count partido programado en ella: conservará su fecha y hora, pero quedará sin cancha. Esta acción no se puede deshacer.|Tiene :count partidos programados en ella: conservarán su fecha y hora, pero quedarán sin cancha. Esta acción no se puede deshacer.', $venue->matches_count, ['count' => $venue->matches_count])
                        : __('Esta acción no se puede deshacer.')"
                >
                    <flux:button variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </x-ui.confirm-delete-form>
            </x-slot:actions>
        </x-ui.page-header>

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('venues.update', $venue) }}" class="space-y-6">
                @csrf
                @method('PUT')

                @include('pages.venues._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                    <flux:button :href="route('venues.index')" variant="ghost" wire:navigate>
                        {{ __('Cancelar') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
