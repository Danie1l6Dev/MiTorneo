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

<flux:select name="status" label="{{ __('Estado') }}">
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

<flux:input
    name="scheduled_at"
    type="datetime-local"
    label="{{ __('Fecha y hora (opcional)') }}"
    value="{{ old('scheduled_at', $match->scheduled_at?->format('Y-m-d\TH:i')) }}"
/>
