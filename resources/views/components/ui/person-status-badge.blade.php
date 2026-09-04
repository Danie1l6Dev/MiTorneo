@props([
    'active' => true,
])

<flux:badge size="sm" :color="$active ? 'green' : 'zinc'">
    {{ $active ? __('Activo') : __('Inactivo') }}
</flux:badge>
