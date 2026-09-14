@php $club ??= null; @endphp

<flux:input
    name="name"
    label="{{ __('Nombre del club') }}"
    value="{{ old('name', $club->name ?? '') }}"
    placeholder="{{ __('Nilmar') }}"
    required
    autofocus
/>
