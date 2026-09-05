<x-layouts::app :title="__('Registrar evento')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Registrar evento')" :subtitle="($match->homeTeam?->name ?? '?').' vs '.($match->awayTeam?->name ?? '?')" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('matches.events.store', $match) }}" class="space-y-6">
                @csrf

                @include('pages.matches.events._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Registrar evento') }}</flux:button>
                    <flux:button :href="route('matches.edit', $match)" variant="ghost" wire:navigate>
                        {{ __('Cancelar') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
