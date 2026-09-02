@php
    $team ??= null;
    $undoRoute ??= null;
@endphp

@if ($team)
    <div class="relative mx-auto flex max-w-sm flex-col items-center gap-3 overflow-hidden rounded-3xl border border-amber-500/40 p-8 text-center glass-panel-strong dark:border-amber-400/30">
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-amber-500/20 via-transparent to-transparent"></div>

        <div class="relative flex size-16 items-center justify-center rounded-full bg-amber-500/20 text-amber-400">
            <flux:icon.trophy variant="outline" class="size-8" />
        </div>
        <div class="relative text-xs font-semibold uppercase tracking-widest text-amber-500 dark:text-amber-400">{{ __('Campeón') }}</div>
        <flux:heading size="xl" class="relative">{{ $team->name }}</flux:heading>

        @if ($undoRoute)
            <form method="POST" action="{{ $undoRoute }}" class="relative" onsubmit="return confirm('{{ __('¿Quitar el campeón declarado para esta fase?') }}')">
                @csrf
                @method('DELETE')
                <flux:button type="submit" variant="ghost" size="sm">{{ __('Quitar campeón') }}</flux:button>
            </form>
        @endif
    </div>
@endif
