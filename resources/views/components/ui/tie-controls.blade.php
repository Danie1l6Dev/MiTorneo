@props([
    'row',
    'previous' => null,
    // The phase the draw belongs to; null on public pages (badge only).
    'drawPhase' => null,
    // Flux badge colour, so each tie block of a table can be told apart.
    'color' => null,
])

@php
    $tie = $row['tie'] ?? null;
    // The draw button / annul link show once per tie, on its first row.
    $firstOfTie = $tie !== null && ($previous['tie_teams'] ?? null) !== $row['tie_teams'];
@endphp

@if ($tie === 'pending')
    <flux:badge size="sm" :color="$color ?? 'amber'" title="{{ __('Empatados en todos los criterios: se resuelve por sorteo') }}">{{ __('Empate total') }}</flux:badge>

    @if ($drawPhase && $firstOfTie)
        <x-ui.confirm-delete-form
            :action="route('phases.tiebreak-draw.store', $drawPhase)"
            method="POST"
            variant="warning"
            icon="sparkles"
            :heading="__('¿Sortear el desempate?')"
            :description="__('Se sorteará el orden entre los equipos empatados. El resultado queda guardado y se puede anular.')"
            :confirm-label="__('Sortear')"
        >
            <flux:button size="xs" variant="primary" icon="sparkles">{{ __('Sortear') }}</flux:button>

            <x-slot:fields>
                @foreach ($row['tie_teams'] as $teamId)
                    <input type="hidden" name="team_ids[]" value="{{ $teamId }}">
                @endforeach
            </x-slot:fields>
        </x-ui.confirm-delete-form>
    @endif
@elseif ($tie === 'drawn')
    <flux:badge size="sm" :color="$color ?? 'blue'" title="{{ __('Desempatado por sorteo') }}">{{ __('Por sorteo') }}</flux:badge>

    @if ($drawPhase && $firstOfTie)
        <x-ui.confirm-delete-form
            :action="route('phases.tiebreak-draw.destroy', [$drawPhase, $row['draw_group']])"
            variant="warning"
            icon="arrow-uturn-left"
            :heading="__('¿Anular el sorteo?')"
            :description="__('El empate volverá a quedar pendiente y se podrá sortear de nuevo.')"
            :confirm-label="__('Anular sorteo')"
        >
            <flux:button size="xs" variant="ghost" icon="arrow-uturn-left">{{ __('Anular') }}</flux:button>
        </x-ui.confirm-delete-form>
    @endif
@endif
