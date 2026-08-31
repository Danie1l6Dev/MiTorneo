<x-layouts::app :title="__('Editar grupo')">
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Editar grupo') }}</flux:heading>
            <flux:text class="mt-1">{{ $group->competitionPhase->name }}</flux:text>
        </div>

        <form method="POST" action="{{ route('groups.update', $group) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @include('pages.groups._fields')

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                <flux:button :href="route('groups.show', $group)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
