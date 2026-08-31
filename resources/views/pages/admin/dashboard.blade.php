<x-layouts::app :title="__('Dashboard administrativo')">
    <div class="w-full space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Dashboard administrativo') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Vista general de la plataforma.') }}</flux:text>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:card class="space-y-1">
                <flux:text class="text-sm text-zinc-500">{{ __('Usuarios totales') }}</flux:text>
                <flux:heading size="xl">{{ $usersCount }}</flux:heading>
            </flux:card>

            <flux:card class="space-y-1">
                <flux:text class="text-sm text-zinc-500">{{ __('Usuarios activos') }}</flux:text>
                <flux:heading size="xl">{{ $activeUsersCount }}</flux:heading>
            </flux:card>

            <flux:card class="space-y-1">
                <flux:text class="text-sm text-zinc-500">{{ __('Administradores') }}</flux:text>
                <flux:heading size="xl">{{ $adminsCount }}</flux:heading>
            </flux:card>

            <flux:card class="space-y-1">
                <flux:text class="text-sm text-zinc-500">{{ __('Torneos totales') }}</flux:text>
                <flux:heading size="xl">{{ $tournamentsCount }}</flux:heading>
            </flux:card>
        </div>

        <div class="flex gap-2">
            <flux:button :href="route('admin.users.index')" variant="primary" wire:navigate>
                {{ __('Ver usuarios') }}
            </flux:button>

            <flux:button :href="route('admin.tournaments.index')" variant="ghost" wire:navigate>
                {{ __('Ver torneos') }}
            </flux:button>
        </div>
    </div>
</x-layouts::app>
