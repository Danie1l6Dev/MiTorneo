@props(['tournament', 'category', 'team'])

@php
    $expelled = $team->isExpelledFrom($tournament);
@endphp

@if ($expelled)
    {{-- Reversible on its own (see TeamExpulsionService::revert()), same as
         undoing a declared champion -- no confirm modal needed. --}}
    <form method="POST" action="{{ route('tournaments.categories.teams.expel.destroy', [$tournament, $category, $team]) }}">
        @csrf
        @method('DELETE')

        <flux:button type="submit" variant="ghost" size="sm" icon="arrow-uturn-left">
            {{ __('Revertir expulsión') }}
        </flux:button>
    </form>
@else
    <flux:button :href="route('tournaments.categories.teams.expel.create', [$tournament, $category, $team])" variant="ghost" size="sm" icon="no-symbol" wire:navigate>
        {{ __('Expulsar') }}
    </flux:button>
@endif
