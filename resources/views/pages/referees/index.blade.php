<x-layouts::app :title="__('Árbitros')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Árbitros')" :subtitle="__('Árbitros disponibles para dirigir tus partidos, reutilizables en cualquier torneo.')">
            <x-slot:actions>
                <flux:button :href="route('referees.create')" variant="primary" icon="plus" wire:navigate>
                    {{ __('Nuevo árbitro') }}
                </flux:button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @if ($referees->isEmpty())
            <x-ui.empty-state icon="flag" :message="__('Todavía no has registrado ningún árbitro.')">
                <x-slot:action>
                    <flux:button :href="route('referees.create')" variant="primary" size="sm" icon="plus" wire:navigate>
                        {{ __('Registrar árbitro') }}
                    </flux:button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="mx-auto grid grid-cols-2 gap-4 sm:gap-5 lg:max-w-md">
                <x-ui.stat-card :label="__('Árbitros registrados')" :value="$referees->count()" icon="flag" color="cyan" />
                <x-ui.stat-card :label="__('Partidos dirigidos')" :value="$referees->sum('matches_count')" icon="calendar-days" color="accent" />
            </div>

            <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                @foreach ($referees as $referee)
                    <x-ui.referee-row :referee="$referee" />
                @endforeach
            </div>
        @endif
    </div>
</x-layouts::app>
