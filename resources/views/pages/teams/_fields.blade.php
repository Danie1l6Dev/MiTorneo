@php
    $team ??= null;
    $category ??= $team?->category;
    $lockedGroup ??= null;
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
    @if ($lockedGroup)
        <div data-flux-field>
            <flux:label>{{ __('Grupo') }}</flux:label>
            <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-600 dark:border-white/10 dark:bg-white/5 dark:text-white/70">
                <flux:icon.squares-2x2 variant="micro" class="size-4 text-amber-400" />
                {{ $lockedGroup->name }}
            </div>
            <input type="hidden" name="group_id" value="{{ $lockedGroup->id }}">
        </div>
    @else
        <flux:select name="group_id" label="{{ __('Grupo') }}" placeholder="{{ __('Selecciona un grupo') }}">
            @php $currentGroup = old('group_id', $team->group_id ?? ''); @endphp

            @foreach ($category->groups as $group)
                <flux:select.option value="{{ $group->id }}" :selected="(string) $group->id === (string) $currentGroup">
                    {{ $group->name }}
                </flux:select.option>
            @endforeach
        </flux:select>
    @endif
@endif
