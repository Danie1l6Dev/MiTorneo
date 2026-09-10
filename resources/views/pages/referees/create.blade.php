<x-layouts::app :title="__('Registrar árbitro')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Registrar árbitro')" :subtitle="__('El árbitro quedará disponible para asignarlo a cualquiera de tus torneos.')" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('referees.store') }}" class="space-y-6">
                @csrf

                @include('pages.referees._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Registrar árbitro') }}</flux:button>
                    <flux:button :href="route('referees.index')" variant="ghost" wire:navigate>
                        {{ __('Cancelar') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
