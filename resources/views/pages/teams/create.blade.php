<x-layouts::app :title="__('Nuevo equipo')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Nuevo equipo')" :subtitle="$category->name" />

        <div class="rounded-xl border border-zinc-200 p-5 dark:border-white/10 glass-panel sm:p-6">
            <form method="POST" action="{{ route('categories.teams.store', $category) }}" class="space-y-6">
                @csrf

                @include('pages.teams._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Crear equipo') }}</flux:button>
                    <flux:button :href="route('categories.show', $category)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
