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
    value="{{ old('document_number', $player->document_number ?? '') }}"
    required
/>

<flux:input
    type="number"
    name="jersey_number"
    label="{{ __('Dorsal') }}"
    min="1"
    max="{{ \App\Http\Requests\PlayerRequest::MAX_JERSEY_NUMBER }}"
    value="{{ old('jersey_number', $player->jersey_number ?? '') }}"
    required
/>
