@props([
    'player',
    'team' => null,
    'readOnly' => false,
])

@php
    $category = $team?->category;
    $ageIneligible = $category && ! $player->ageEligibleForCategory($category);
@endphp

{{--
    The whole row links to the player's ficha (stats, history, everything)
    via a "stretched link" -- an invisible <a> covering the row (absolute
    inset-0) instead of wrapping the row's content in one, so it never nests
    inside the action buttons/forms on the right (invalid HTML, unreliable
    clicks). Those keep working normally: `relative` raises them above the
    stretched link in stacking order, which is what lets clicks land on the
    button instead of falling through to the row's link underneath it.
--}}
<div {{ $attributes->class('relative flex items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-zinc-50 dark:hover:bg-white/5' . ($player->is_active ? '' : ' opacity-60')) }}>
    <a href="{{ route('players.show', $player) }}" wire:navigate class="absolute inset-0" aria-label="{{ __('Ver ficha de :name', ['name' => $player->full_name]) }}"></a>

    <div class="pointer-events-none flex min-w-0 items-center gap-3">
        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent-content/15 font-display text-base font-bold tabular-nums text-accent-content">
            {{ $player->jersey_number ?? '–' }}
        </div>

        <div class="min-w-0">
            <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $player->full_name }}</div>
            <div class="truncate text-xs text-zinc-500 dark:text-white/50">{{ __('Documento') }}: {{ $player->document_number ?? __('Sin registrar') }}</div>
        </div>
    </div>

    <div class="relative flex shrink-0 items-center gap-1.5">
        @unless ($player->birth_date)
            <flux:tooltip :content="__('Falta la fecha de nacimiento -- necesaria para sumarlo a otra categoría')">
                <flux:icon.exclamation-triangle variant="micro" class="size-4 text-amber-500" />
            </flux:tooltip>
        @endunless

        @unless ($player->gender)
            <flux:tooltip :content="__('Falta el género -- necesario en categorías mixtas con años extra permitidos para mujeres')">
                <flux:icon.exclamation-triangle variant="micro" class="size-4 text-amber-500" />
            </flux:tooltip>
        @endunless

        @if ($ageIneligible && ! $readOnly)
            <flux:tooltip :content="__('Ya no es permitido en esta categoría este jugador -- click para promoverlo')">
                <flux:button :href="route('teams.players.promote.create', [$team, $player])" variant="ghost" size="sm" icon="arrow-up-circle" class="text-red-500" wire:navigate />
            </flux:tooltip>
        @endif

        <x-ui.person-status-badge :active="$player->is_active" />

        @unless ($readOnly)
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
        @endunless
    </div>
</div>
