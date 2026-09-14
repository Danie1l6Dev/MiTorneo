@php
    $player ??= null;
@endphp

<flux:input
    name="full_name"
    label="{{ __('Nombre completo') }}"
    value="{{ old('full_name', $player->full_name ?? '') }}"
    required
    autofocus
/>

<flux:input
    name="document_number"
    label="{{ __('Documento') }}"
    description="{{ __('Opcional') }}"
    value="{{ old('document_number', $player->document_number ?? '') }}"
/>

<flux:input
    type="date"
    name="birth_date"
    label="{{ __('Fecha de nacimiento') }}"
    description="{{ __('Opcional, pero se necesita para poder sumarlo a otra categoría más adelante') }}"
    value="{{ old('birth_date', optional($player->birth_date ?? null)->format('Y-m-d')) }}"
    x-model="birthDate"
/>

<flux:input
    type="number"
    name="jersey_number"
    label="{{ __('Dorsal') }}"
    description="{{ __('Opcional') }}"
    min="1"
    max="{{ \App\Http\Requests\PlayerRequest::MAX_JERSEY_NUMBER }}"
    value="{{ old('jersey_number', $player->jersey_number ?? '') }}"
/>
