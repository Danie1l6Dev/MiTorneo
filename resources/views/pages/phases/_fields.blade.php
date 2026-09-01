@php $phase ??= null; @endphp

<flux:input
    name="name"
    label="{{ __('Nombre de la fase') }}"
    value="{{ old('name', $phase->name ?? '') }}"
    placeholder="{{ __('Fase de grupos') }}"
    required
    autofocus
/>

<flux:select name="type" label="{{ __('Tipo') }}">
    @php $currentType = old('type', $phase->type?->value ?? \App\Enums\CompetitionPhaseType::League->value); @endphp

    @foreach (\App\Enums\CompetitionPhaseType::cases() as $type)
        <flux:select.option value="{{ $type->value }}" :selected="$type->value === $currentType">
            {{ $type->label() }}
        </flux:select.option>
    @endforeach
</flux:select>

<flux:input
    name="order"
    type="number"
    label="{{ __('Orden') }}"
    value="{{ old('order', $phase->order ?? 0) }}"
    min="0"
/>
