<x-layouts::app :title="__('Registrar cancha')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Registrar cancha')" :subtitle="__('La cancha quedará disponible para programar partidos en cualquiera de tus torneos.')" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('venues.store') }}" class="space-y-6">
                @csrf

                @include('pages.venues._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Registrar cancha') }}</flux:button>
                    <flux:button :href="route('venues.index')" variant="ghost" wire:navigate>
                        {{ __('Cancelar') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
