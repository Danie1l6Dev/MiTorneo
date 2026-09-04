<x-layouts::app :title="__('Registrar director técnico')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Registrar director técnico')" :subtitle="$team->name" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('teams.coach.store', $team) }}" class="space-y-6">
                @csrf

                @include('pages.coaches._fields')

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Registrar DT') }}</flux:button>
                    <flux:button :href="route('teams.show', $team)" variant="ghost" wire:navigate>
                        {{ __('Cancelar') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
