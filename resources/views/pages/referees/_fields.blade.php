@php
    $referee ??= null;
@endphp

<flux:input
    name="full_name"
    label="{{ __('Nombre completo') }}"
    value="{{ old('full_name', $referee->full_name ?? '') }}"
    required
    autofocus
/>

<flux:input
    name="document_number"
    label="{{ __('Documento') }}"
    value="{{ old('document_number', $referee->document_number ?? '') }}"
    required
/>
