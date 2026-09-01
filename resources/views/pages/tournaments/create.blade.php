<x-layouts::app :title="__('Nuevo torneo')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Nuevo torneo')" :subtitle="__('Crea un torneo para empezar a organizar sus categorías, fases y partidos.')" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('tournaments.store') }}" class="space-y-6">
                @csrf

                @include('pages.tournaments._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Crear torneo') }}</flux:button>
                    <flux:button :href="route('dashboard')" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
