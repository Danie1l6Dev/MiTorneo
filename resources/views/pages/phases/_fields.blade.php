@php
    $phase ??= null;
    $typeOptions ??= null;
    $typeIsLocked ??= false;
@endphp

<flux:input
    name="name"
    label="{{ __('Nombre de la fase') }}"
    value="{{ old('name', $phase->name ?? '') }}"
    placeholder="{{ __('Fase de grupos') }}"
    required
    autofocus
/>

@php
    $currentType = old('type', $phase->type?->value ?? \App\Enums\CompetitionPhaseType::League->value);
    $options = $typeOptions ?? collect(\App\Enums\CompetitionPhaseType::cases())
        ->map(fn ($type) => ['type' => $type, 'available' => true, 'reason' => null]);
    $currentFormat = old('knockout_format', $phase->knockout_format?->value ?? \App\Enums\ScheduleFormat::SingleRound->value);
@endphp

<div class="space-y-4" x-data="{ type: '{{ $currentType }}' }">
    <div class="space-y-1.5">
        {{-- A disabled <select> never submits its value, so the locked state
             keeps it purely for display (no name) and carries the real value
             through a separate hidden input instead. --}}
        <flux:select :name="$typeIsLocked ? null : 'type'" label="{{ __('Tipo') }}" :disabled="$typeIsLocked" x-model="type">
            @foreach ($options as $option)
                <flux:select.option
                    value="{{ $option['type']->value }}"
                    :selected="$option['type']->value === $currentType"
                    :disabled="! $option['available']"
                >
                    {{ $option['type']->label() }}@if (! $option['available']) — {{ __('no disponible') }}@endif
                </flux:select.option>
            @endforeach
        </flux:select>

        @if ($typeIsLocked)
            <input type="hidden" name="type" value="{{ $currentType }}">

            <flux:text class="text-xs text-zinc-500">
                {{ __('Esta fase ya tiene partidos generados: su tipo no se puede cambiar.') }}
            </flux:text>
        @else
            @foreach (collect($typeOptions)->where('available', false) as $option)
                <flux:text class="text-xs text-zinc-500">{{ $option['reason'] }}</flux:text>
            @endforeach
        @endif
    </div>

    {{--
        A league phase submits no knockout_format at all, so this has to be
        removed from the DOM -- not just hidden -- when "Liga" is picked:
        Flux's radio is a form-associated custom element, and x-show +
        x-bind:disabled left its hidden internal input still submitting its
        last-checked value (see the same note on advance.blade.php's
        draw_method).
    --}}
    <template x-if="type !== '{{ \App\Enums\CompetitionPhaseType::League->value }}'">
        <div class="space-y-1.5">
            @if ($typeIsLocked)
                <flux:text class="text-xs font-medium text-zinc-500">{{ __('Formato de los cruces') }}</flux:text>
                <flux:text class="text-sm">{{ $currentFormat === \App\Enums\ScheduleFormat::HomeAndAway->value ? __('Ida y vuelta') : __('Partido único') }}</flux:text>
                <input type="hidden" name="knockout_format" value="{{ $currentFormat }}">
                <flux:text class="text-xs text-zinc-500">
                    {{ __('Esta fase ya tiene partidos generados: su formato de cruces no se puede cambiar.') }}
                </flux:text>
            @else
                <flux:radio.group name="knockout_format" label="{{ __('Formato de los cruces') }}">
                    @foreach (\App\Enums\ScheduleFormat::cases() as $format)
                        <flux:radio
                            value="{{ $format->value }}"
                            label="{{ $format === \App\Enums\ScheduleFormat::HomeAndAway ? __('Ida y vuelta') : __('Partido único') }}"
                            description="{{ $format === \App\Enums\ScheduleFormat::HomeAndAway ? __('Cada cruce se juega en dos partidos; el resultado global decide quién avanza.') : __('Un solo partido decide el cruce.') }}"
                            :checked="$currentFormat === $format->value"
                        />
                    @endforeach
                </flux:radio.group>
            @endif
        </div>
    </template>
</div>

@if ($phase)
    <flux:input
        name="order"
        type="number"
        label="{{ __('Orden') }}"
        value="{{ old('order', $phase->order) }}"
        min="0"
    />
@endif
