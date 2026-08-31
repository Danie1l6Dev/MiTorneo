@php $category ??= null; @endphp

<flux:input
    name="name"
    label="{{ __('Nombre de la categoría') }}"
    value="{{ old('name', $category->name ?? '') }}"
    placeholder="{{ __('Juvenil') }}"
    required
    autofocus
/>

<flux:input
    name="order"
    type="number"
    label="{{ __('Orden') }}"
    value="{{ old('order', $category->order ?? 0) }}"
    min="0"
/>
