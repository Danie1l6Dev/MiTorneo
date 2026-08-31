@php
    $team ??= null;
    $category ??= $team?->category;
@endphp

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

@if ($category?->uses_groups)
    <flux:select name="group_id" label="{{ __('Grupo') }}" placeholder="{{ __('Selecciona un grupo') }}">
        @php $currentGroup = old('group_id', $team->group_id ?? ''); @endphp

        @foreach ($category->groups as $group)
            <flux:select.option value="{{ $group->id }}" :selected="(string) $group->id === (string) $currentGroup">
                {{ $group->name }}
            </flux:select.option>
        @endforeach
    </flux:select>
@endif
