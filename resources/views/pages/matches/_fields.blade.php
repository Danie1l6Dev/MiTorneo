<div>
    <flux:heading size="sm" class="mb-1">{{ __('Grupo') }}</flux:heading>
    <flux:text class="text-zinc-500 dark:text-white/60">{{ $match->group?->name ?? __('Sin grupo') }}</flux:text>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <flux:heading size="sm" class="mb-1">{{ __('Equipo local') }}</flux:heading>
        <flux:text class="text-zinc-500 dark:text-white/60">{{ $match->homeTeam?->name ?? __('Por definir') }}</flux:text>
    </div>
    <div>
        <flux:heading size="sm" class="mb-1">{{ __('Equipo visitante') }}</flux:heading>
        <flux:text class="text-zinc-500 dark:text-white/60">{{ $match->awayTeam?->name ?? __('Por definir') }}</flux:text>
    </div>
</div>

@php $readonly ??= false; @endphp

<flux:select name="status" label="{{ __('Estado') }}" :disabled="$readonly">
    @php $currentStatus = old('status', $match->status?->value ?? \App\Enums\MatchStatus::Scheduled->value); @endphp

    @foreach (\App\Enums\MatchStatus::cases() as $status)
        <flux:select.option value="{{ $status->value }}" :selected="$status->value === $currentStatus">
            {{ $status->label() }}
        </flux:select.option>
    @endforeach
</flux:select>

<div>
    <flux:heading size="sm" class="mb-1">{{ __('Jornada') }}</flux:heading>
    <flux:text class="text-zinc-500 dark:text-white/60">{{ $match->round_number ?? __('Sin jornada asignada') }}</flux:text>
</div>

{{-- When/where it's played. Posted together with the status and referee in the
     one "Guardar cambios" form, so every one of them is checked against the
     calendar at once (TournamentMatchRequest). --}}
<div class="grid gap-4 sm:grid-cols-2">
    <flux:input
        name="scheduled_date"
        type="date"
        label="{{ __('Día') }}"
        value="{{ old('scheduled_date', $match->scheduled_at?->format('Y-m-d')) }}"
        :disabled="$readonly"
    />

    <flux:input
        name="kickoff_time"
        type="time"
        label="{{ __('Hora (opcional)') }}"
        value="{{ old('kickoff_time', $match->hasKickoffTime() ? $match->scheduled_at->format('H:i') : '') }}"
        :disabled="$readonly"
    />
</div>

<flux:select name="venue_id" label="{{ __('Cancha (opcional)') }}" placeholder="{{ __('Sin cancha asignada') }}" :disabled="$readonly">
    @php $currentVenue = old('venue_id', $match->venue_id ?? ''); @endphp

    @foreach ($venues as $venue)
        <flux:select.option value="{{ $venue->id }}" :selected="(string) $venue->id === (string) $currentVenue">
            {{ $venue->name }}
        </flux:select.option>
    @endforeach
</flux:select>

@unless ($readonly)
    @if ($venues->isEmpty())
        <flux:text class="text-xs text-zinc-500 dark:text-white/50">
            {{ __('Aún no tienes canchas registradas.') }}
            <a href="{{ route('venues.create') }}" wire:navigate class="underline">{{ __('Registrar una cancha') }}</a>
        </flux:text>
    @endif

    <flux:text class="text-xs text-zinc-500 dark:text-white/50">
        {{ __('Si dejas el día vacío, el partido queda sin fecha (y postergado si ya tenía una).') }}
    </flux:text>
@endunless

<flux:select name="referee_id" label="{{ __('Árbitro (opcional)') }}" placeholder="{{ __('Sin árbitro asignado') }}" :disabled="$readonly">
    @php $currentReferee = old('referee_id', $match->referee_id ?? ''); @endphp

    @foreach ($referees as $referee)
        <flux:select.option value="{{ $referee->id }}" :selected="(string) $referee->id === (string) $currentReferee">
            {{ $referee->full_name }}
        </flux:select.option>
    @endforeach
</flux:select>
