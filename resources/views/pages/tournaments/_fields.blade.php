@php $tournament ??= null; @endphp

<flux:input
    name="name"
    label="{{ __('Nombre del torneo') }}"
    value="{{ old('name', $tournament->name ?? '') }}"
    placeholder="{{ __('Campeonato Municipal 2026') }}"
    required
    autofocus
/>

<flux:textarea
    name="description"
    label="{{ __('Descripción') }}"
    rows="3"
>{{ old('description', $tournament->description ?? '') }}</flux:textarea>

<flux:input
    name="season"
    label="{{ __('Temporada') }}"
    value="{{ old('season', $tournament->season ?? '') }}"
    placeholder="2026"
/>

<flux:select name="status" label="{{ __('Estado') }}">
    @php $currentStatus = old('status', $tournament->status?->value ?? \App\Enums\TournamentStatus::Draft->value); @endphp

    @foreach (\App\Enums\TournamentStatus::cases() as $status)
        <flux:select.option value="{{ $status->value }}" :selected="$status->value === $currentStatus">
            {{ $status->label() }}
        </flux:select.option>
    @endforeach
</flux:select>
