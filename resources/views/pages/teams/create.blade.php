<x-layouts::app :title="__('Nuevo equipo')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Nuevo equipo')" :subtitle="$lockedGroup ? $category->name.' · '.$lockedGroup->name : $category->name" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('categories.teams.store', $category) }}" class="space-y-6">
                @csrf

                @include('pages.teams._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Crear equipo') }}</flux:button>
                    <flux:button :href="$lockedGroup ? route('groups.show', $lockedGroup) : route('categories.show', $category)" variant="ghost" wire:navigate>
                        {{ __('Cancelar') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
