<x-layouts::app :title="__('Dashboard administrativo')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header
            :eyebrow="__('Administración')"
            :title="__('Dashboard administrativo')"
            :subtitle="__('Vista general de la plataforma.')"
        />

        <div class="mx-auto grid grid-cols-2 gap-4 sm:grid-cols-4 sm:gap-5 lg:max-w-3xl">
            <x-ui.stat-card :label="__('Usuarios totales')" :value="$usersCount" icon="users" color="cyan" />
            <x-ui.stat-card :label="__('Usuarios activos')" :value="$activeUsersCount" icon="user-group" color="green" />
            <x-ui.stat-card :label="__('Administradores')" :value="$adminsCount" icon="shield-check" color="amber" />
            <x-ui.stat-card :label="__('Torneos totales')" :value="$tournamentsCount" icon="trophy" color="accent" />
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('admin.users.index')" variant="primary" icon="users" wire:navigate>
                {{ __('Ver usuarios') }}
            </flux:button>

            <flux:button :href="route('admin.tournaments.index')" variant="ghost" icon="trophy" wire:navigate>
                {{ __('Ver torneos') }}
            </flux:button>
        </div>
    </div>
</x-layouts::app>
