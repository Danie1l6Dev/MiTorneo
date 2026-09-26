@php
    $venue ??= null;
@endphp

<flux:input
    name="name"
    label="{{ __('Nombre de la cancha') }}"
    value="{{ old('name', $venue->name ?? '') }}"
    placeholder="{{ __('Ej.: Cancha Parque Boscán') }}"
    maxlength="120"
    required
    autofocus
/>
