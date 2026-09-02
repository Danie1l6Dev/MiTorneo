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

<div class="space-y-1.5">
    @php
        $currentType = old('type', $phase->type?->value ?? \App\Enums\CompetitionPhaseType::League->value);
        $options = $typeOptions ?? collect(\App\Enums\CompetitionPhaseType::cases())
            ->map(fn ($type) => ['type' => $type, 'available' => true, 'reason' => null]);
    @endphp

    {{-- A disabled <select> never submits its value, so the locked state keeps
         it purely for display (no name) and carries the real value through a
         separate hidden input instead. --}}
    <flux:select :name="$typeIsLocked ? null : 'type'" label="{{ __('Tipo') }}" :disabled="$typeIsLocked">
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

@if ($phase)
    <flux:input
        name="order"
        type="number"
        label="{{ __('Orden') }}"
        value="{{ old('order', $phase->order) }}"
        min="0"
    />
@endif
