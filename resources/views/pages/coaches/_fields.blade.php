@php
    $coach ??= null;
@endphp

<flux:input
    name="full_name"
    label="{{ __('Nombre completo') }}"
    value="{{ old('full_name', $coach->full_name ?? '') }}"
    required
    autofocus
/>

<flux:input
    name="document_number"
    label="{{ __('Documento') }}"
    value="{{ old('document_number', $coach->document_number ?? '') }}"
    required
/>
