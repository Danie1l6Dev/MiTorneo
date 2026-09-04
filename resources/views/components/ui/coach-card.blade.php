@props([
    'team',
    'coach' => null,
])

<div {{ $attributes->class('rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel') }}>
    @if ($coach)
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-accent-content/15 text-accent-content">
                    <flux:icon.identification variant="micro" class="size-5" />
                </div>

                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <div class="truncate text-sm font-semibold text-zinc-800 dark:text-white">{{ $coach->full_name }}</div>
                        <x-ui.person-status-badge :active="$coach->is_active" />
                    </div>
                    <div class="truncate text-xs text-zinc-500 dark:text-white/50">{{ __('Documento') }}: {{ $coach->document_number }}</div>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <flux:button :href="route('coaches.edit', $coach)" variant="ghost" size="sm" icon="pencil" wire:navigate>
                    {{ __('Editar DT') }}
                </flux:button>

                <form method="POST" action="{{ route('coaches.toggle-active', $coach) }}" onsubmit="return confirm('{{ __('¿Desactivar a :name como director técnico?', ['name' => $coach->full_name]) }}')">
                    @csrf
                    @method('PATCH')
                    <flux:button type="submit" variant="ghost" size="sm" icon="x-circle">{{ __('Desactivar DT') }}</flux:button>
                </form>
            </div>
        </div>
    @else
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
            <flux:text class="text-zinc-500 dark:text-white/60">{{ __('No registrado') }}</flux:text>

            <flux:button :href="route('teams.coach.create', $team)" variant="primary" size="sm" icon="plus" wire:navigate>
                {{ __('Registrar DT') }}
            </flux:button>
        </div>
    @endif
</div>
