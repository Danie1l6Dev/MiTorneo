<x-layouts::app :title="__('Nueva categoría')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Nueva categoría')" :subtitle="$tournament->name" />

        <div class="rounded-xl border border-zinc-200 p-5 dark:border-white/10 glass-panel sm:p-6">
            <form method="POST" action="{{ route('tournaments.categories.store', $tournament) }}" class="space-y-6">
                @csrf

                @include('pages.categories._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Crear categoría') }}</flux:button>
                    <flux:button :href="route('tournaments.show', $tournament)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
