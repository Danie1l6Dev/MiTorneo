@php
    $event ??= null;
@endphp

<flux:select name="type" label="{{ __('Tipo de evento') }}">
    @php $currentType = old('type', $event->type?->value ?? \App\Enums\MatchEventType::Goal->value); @endphp

    {{-- Plain text here: a native <option> can't render an SVG icon --}}
    @foreach (\App\Enums\MatchEventType::cases() as $type)
        <flux:select.option value="{{ $type->value }}" :selected="$type->value === $currentType">
            {{ $type->label() }}
        </flux:select.option>
    @endforeach
</flux:select>

{{-- One combined list (players + each team's DT) instead of two dependent
     selects -- the option value is "player:{id}" or "coach:{id}";
     MatchEventRequest::prepareForValidation() splits it back into the
     player_id/coach_id the backend actually stores. A DT chosen here still
     only accepts Amarilla/Roja server-side, same as the quick-add panels. --}}
<flux:select name="subject" label="{{ __('Jugador o DT') }}" placeholder="{{ __('Selecciona un jugador o DT') }}">
    @php
        $currentSubject = old('subject', $event
            ? ($event->coach_id ? 'coach:'.$event->coach_id : 'player:'.$event->player_id)
            : '');
    @endphp

    @foreach ($players as $player)
        <flux:select.option value="player:{{ $player->id }}" :selected="$currentSubject === 'player:'.$player->id">
            #{{ $player->jersey_number }} {{ $player->full_name }} ({{ $player->team->name }})
        </flux:select.option>
    @endforeach

    @foreach ($coaches as $coach)
        <flux:select.option value="coach:{{ $coach->id }}" :selected="$currentSubject === 'coach:'.$coach->id">
            {{ __('DT') }}: {{ $coach->full_name }} ({{ $coach->team->name }})
        </flux:select.option>
    @endforeach
</flux:select>

<flux:input
    type="number"
    name="minute"
    label="{{ __('Minuto (opcional)') }}"
    min="0"
    max="{{ \App\Http\Requests\MatchEventRequest::MAX_MINUTE }}"
    value="{{ old('minute', $event->minute ?? '') }}"
/>
