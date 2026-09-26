<x-layouts::app :title="__('Canchas')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Canchas')" :subtitle="__('Canchas y lugares donde se juegan tus partidos, reutilizables en cualquier torneo.')">
            <x-slot:actions>
                <flux:button :href="route('venues.create')" variant="primary" icon="plus" wire:navigate>
                    {{ __('Nueva cancha') }}
                </flux:button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @if ($venues->isEmpty())
            <x-ui.empty-state icon="map-pin" :message="__('Todavía no has registrado ninguna cancha.')">
                <x-slot:action>
                    <flux:button :href="route('venues.create')" variant="primary" size="sm" icon="plus" wire:navigate>
                        {{ __('Registrar cancha') }}
                    </flux:button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="mx-auto grid grid-cols-2 gap-4 sm:gap-5 lg:max-w-md">
                <x-ui.stat-card :label="__('Canchas registradas')" :value="$venues->count()" icon="map-pin" color="cyan" />
                <x-ui.stat-card :label="__('Partidos programados')" :value="$venues->sum('matches_count')" icon="calendar-days" color="accent" />
            </div>

            <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                @foreach ($venues as $venue)
                    <x-ui.venue-row :venue="$venue" />
                @endforeach
            </div>
        @endif
    </div>
</x-layouts::app>
