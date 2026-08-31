@php $category ??= null; @endphp

<flux:input
    name="name"
    label="{{ __('Nombre de la categoría') }}"
    value="{{ old('name', $category->name ?? '') }}"
    placeholder="{{ __('Juvenil') }}"
    required
    autofocus
/>

<flux:textarea
    name="description"
    label="{{ __('Descripción (opcional)') }}"
    rows="3"
>{{ old('description', $category->description ?? '') }}</flux:textarea>

<flux:select name="status" label="{{ __('Estado') }}">
    @php $currentStatus = old('status', $category->status?->value ?? \App\Enums\CategoryStatus::Active->value); @endphp

    @foreach (\App\Enums\CategoryStatus::cases() as $status)
        <flux:select.option value="{{ $status->value }}" :selected="$status->value === $currentStatus">
            {{ $status->label() }}
        </flux:select.option>
    @endforeach
</flux:select>

<flux:checkbox
    name="uses_groups"
    value="1"
    label="{{ __('Esta categoría se organiza en grupos') }}"
    description="{{ __('Por ejemplo, Grupo A y Grupo B. Si no la marcas, los equipos se listan directamente en la categoría.') }}"
    :checked="(bool) old('uses_groups', $category->uses_groups ?? false)"
/>

<flux:input
    name="order"
    type="number"
    label="{{ __('Orden') }}"
    value="{{ old('order', $category->order ?? 0) }}"
    min="0"
/>
