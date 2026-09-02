@php
    $match ??= null;
    $phase ??= $match?->competitionPhase;
    // Group, teams and round number are only decided once, when the match is created
    // (they drive the schedule/bracket it belongs to); editing an existing match can
    // no longer change them, so they're shown read-only instead of as inputs.
    $locked = $match !== null;
@endphp

@if ($locked)
    <div>
        <flux:heading size="sm" class="mb-1">{{ __('Grupo') }}</flux:heading>
        <flux:text class="text-zinc-500 dark:text-white/60">{{ $match->group?->name ?? __('Sin grupo') }}</flux:text>
    </div>
@else
    <flux:select name="group_id" label="{{ __('Grupo (opcional)') }}" placeholder="{{ __('Sin grupo') }}">
        @php $currentGroup = old('group_id', ''); @endphp

        @foreach ($groups as $group)
            <flux:select.option value="{{ $group->id }}" :selected="(string) $group->id === (string) $currentGroup">
                {{ $group->name }}
            </flux:select.option>
        @endforeach
    </flux:select>
@endif

@if ($locked)
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
@else
    <div class="grid gap-4 sm:grid-cols-2">
        <flux:select name="home_team_id" label="{{ __('Equipo local') }}">
            @php $currentHome = old('home_team_id', ''); @endphp

            @foreach ($teams as $team)
                <flux:select.option value="{{ $team->id }}" :selected="(string) $team->id === (string) $currentHome">
                    {{ $team->name }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:select name="away_team_id" label="{{ __('Equipo visitante') }}">
            @php $currentAway = old('away_team_id', ''); @endphp

            @foreach ($teams as $team)
                <flux:select.option value="{{ $team->id }}" :selected="(string) $team->id === (string) $currentAway">
                    {{ $team->name }}
                </flux:select.option>
            @endforeach
        </flux:select>
    </div>
@endif

<flux:select name="status" label="{{ __('Estado') }}">
    @php $currentStatus = old('status', $match->status?->value ?? \App\Enums\MatchStatus::Scheduled->value); @endphp

    @foreach (\App\Enums\MatchStatus::cases() as $status)
        <flux:select.option value="{{ $status->value }}" :selected="$status->value === $currentStatus">
            {{ $status->label() }}
        </flux:select.option>
    @endforeach
</flux:select>

@if ($locked)
    <div>
        <flux:heading size="sm" class="mb-1">{{ __('Jornada') }}</flux:heading>
        <flux:text class="text-zinc-500 dark:text-white/60">{{ $match->round_number ?? __('Sin jornada asignada') }}</flux:text>
    </div>
@else
    <flux:input
        name="round_number"
        type="number"
        min="1"
        label="{{ __('Jornada') }}"
        value="{{ old('round_number', '') }}"
        placeholder="{{ __('1') }}"
    />
@endif

<flux:input
    name="scheduled_at"
    type="datetime-local"
    label="{{ __('Fecha y hora (opcional)') }}"
    value="{{ old('scheduled_at', $match?->scheduled_at?->format('Y-m-d\TH:i')) }}"
/>
