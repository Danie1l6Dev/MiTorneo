@php $team ??= null; @endphp

<flux:input
    name="name"
    label="{{ __('Nombre del equipo') }}"
    value="{{ old('name', $team->name ?? '') }}"
    required
    autofocus
/>

<flux:input
    name="short_name"
    label="{{ __('Abreviatura') }}"
    value="{{ old('short_name', $team->short_name ?? '') }}"
    maxlength="10"
/>
