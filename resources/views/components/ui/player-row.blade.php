@props([
    'player',
])

<div {{ $attributes->class('flex items-center justify-between gap-3 px-4 py-3' . ($player->is_active ? '' : ' opacity-60')) }}>
    <div class="flex min-w-0 items-center gap-3">
        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent-content/15 font-display text-base font-bold tabular-nums text-accent-content">
            {{ $player->jersey_number }}
        </div>

        <div class="min-w-0">
            <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $player->full_name }}</div>
            <div class="truncate text-xs text-zinc-500 dark:text-white/50">{{ __('Documento') }}: {{ $player->document_number }}</div>
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-1.5">
        <x-ui.person-status-badge :active="$player->is_active" />

        <flux:tooltip :content="__('Editar')">
            <flux:button :href="route('players.edit', $player)" variant="ghost" size="sm" icon="pencil" wire:navigate />
        </flux:tooltip>

        <form method="POST" action="{{ route('players.toggle-active', $player) }}" onsubmit="return confirm('{{ $player->is_active ? __('¿Desactivar a :name?', ['name' => $player->full_name]) : __('¿Activar a :name?', ['name' => $player->full_name]) }}')">
            @csrf
            @method('PATCH')
            <flux:tooltip :content="$player->is_active ? __('Desactivar') : __('Activar')">
                <flux:button type="submit" variant="ghost" size="sm" :icon="$player->is_active ? 'x-circle' : 'check-circle'" />
            </flux:tooltip>
        </form>
    </div>
</div>
