@php $group ??= null; @endphp

<flux:input
    name="name"
    label="{{ __('Nombre del grupo') }}"
    value="{{ old('name', $group->name ?? '') }}"
    placeholder="{{ __('Grupo A') }}"
    required
    autofocus
/>

<flux:input
    name="order"
    type="number"
    label="{{ __('Orden') }}"
    value="{{ old('order', $group->order ?? 0) }}"
    min="0"
/>
