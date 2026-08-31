<x-layouts::app :title="__('Dashboard')">
    <div class="w-full space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Bienvenido, :name.', ['name' => auth()->user()->name]) }}</flux:text>
            </div>

            <flux:button :href="route('tournaments.create')" variant="primary" icon="plus" wire:navigate>
                {{ __('Nuevo torneo') }}
            </flux:button>
        </div>

        <flux:card class="max-w-xs space-y-1">
            <flux:text class="text-sm text-zinc-500">{{ __('Tus torneos') }}</flux:text>
            <flux:heading size="xl">{{ $tournamentsCount }}</flux:heading>
        </flux:card>

        <flux:separator />

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Torneos recientes') }}</flux:heading>

                <flux:button :href="route('tournaments.index')" variant="ghost" size="sm" wire:navigate>
                    {{ __('Ver todos') }}
                </flux:button>
            </div>

            @if ($tournaments->isEmpty())
                <flux:text class="text-zinc-500">{{ __('Todavía no has creado ningún torneo.') }}</flux:text>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($tournaments as $tournament)
                        <a href="{{ route('tournaments.show', $tournament) }}" wire:navigate class="block">
                            <flux:card class="h-full space-y-2 hover:border-zinc-300 dark:hover:border-white/20">
                                <div class="flex items-start justify-between gap-2">
                                    <flux:heading>{{ $tournament->name }}</flux:heading>
                                    <flux:badge size="sm">{{ $tournament->status->label() }}</flux:badge>
                                </div>
                                <flux:text class="text-sm text-zinc-500">
                                    {{ $tournament->season ?? __('Sin temporada') }}
                                </flux:text>
                            </flux:card>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
