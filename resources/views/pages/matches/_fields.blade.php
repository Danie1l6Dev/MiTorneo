@php
    $match ??= null;
    $phase ??= $match?->competitionPhase;
@endphp

<flux:select name="group_id" label="{{ __('Grupo (opcional)') }}" placeholder="{{ __('Sin grupo') }}">
    @php $currentGroup = old('group_id', $match->group_id ?? ''); @endphp

    @foreach ($groups as $group)
        <flux:select.option value="{{ $group->id }}" :selected="(string) $group->id === (string) $currentGroup">
            {{ $group->name }}
        </flux:select.option>
    @endforeach
</flux:select>

<div class="grid gap-4 sm:grid-cols-2">
    <flux:select name="home_team_id" label="{{ __('Equipo local') }}">
        @php $currentHome = old('home_team_id', $match->home_team_id ?? ''); @endphp

        @foreach ($teams as $team)
            <flux:select.option value="{{ $team->id }}" :selected="(string) $team->id === (string) $currentHome">
                {{ $team->name }}
            </flux:select.option>
        @endforeach
    </flux:select>

    <flux:select name="away_team_id" label="{{ __('Equipo visitante') }}">
        @php $currentAway = old('away_team_id', $match->away_team_id ?? ''); @endphp

        @foreach ($teams as $team)
            <flux:select.option value="{{ $team->id }}" :selected="(string) $team->id === (string) $currentAway">
                {{ $team->name }}
            </flux:select.option>
        @endforeach
    </flux:select>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <flux:input
        name="home_score"
        type="number"
        min="0"
        label="{{ __('Goles local') }}"
        value="{{ old('home_score', $match->home_score ?? '') }}"
    />

    <flux:input
        name="away_score"
        type="number"
        min="0"
        label="{{ __('Goles visitante') }}"
        value="{{ old('away_score', $match->away_score ?? '') }}"
    />
</div>

<flux:select name="status" label="{{ __('Estado') }}">
    @php $currentStatus = old('status', $match->status?->value ?? \App\Enums\MatchStatus::Scheduled->value); @endphp

    @foreach (\App\Enums\MatchStatus::cases() as $status)
        <flux:select.option value="{{ $status->value }}" :selected="$status->value === $currentStatus">
            {{ $status->label() }}
        </flux:select.option>
    @endforeach
</flux:select>

<flux:input
    name="round_number"
    type="number"
    min="1"
    label="{{ __('Jornada') }}"
    value="{{ old('round_number', $match->round_number ?? '') }}"
    placeholder="{{ __('1') }}"
/>

<flux:input
    name="scheduled_at"
    type="datetime-local"
    label="{{ __('Fecha y hora') }}"
    value="{{ old('scheduled_at', $match?->scheduled_at?->format('Y-m-d\TH:i')) }}"
/>
