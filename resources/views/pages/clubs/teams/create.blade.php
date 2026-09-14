@php
    $categoriesWithGroups = $categories->filter->uses_groups->keyBy('id');
@endphp

<x-layouts::app :title="__('Nuevo plantel')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up" x-data="{ categoryId: '{{ old('category_id') }}', categories: {{ $categoriesWithGroups->map->only(['id', 'name'])->values()->toJson() }} }">
        <x-ui.page-header :title="__('Nuevo plantel')" :subtitle="$club->name" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('clubs.teams.store', $club) }}" class="space-y-6">
                @csrf

                <flux:select name="category_id" label="{{ __('Categoría') }}" x-model="categoryId" required>
                    <flux:select.option value="">{{ __('Elige una categoría') }}</flux:select.option>
                    @foreach ($categories as $category)
                        <flux:select.option value="{{ $category->id }}" :selected="old('category_id') == $category->id">
                            {{ $category->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                {{--
                    One <select name="group_id"> per group-using category,
                    shown/hidden by x-show as the category changes -- but
                    x-show only toggles visibility, it doesn't remove a
                    hidden one from the form. Without :disabled, every
                    hidden select would still submit its own value under
                    the same "group_id" name, and the browser would just
                    pick whichever happens to come last in the DOM instead
                    of the one actually chosen. A disabled field is
                    excluded from submission entirely, so only the one
                    matching the selected category ever sends its value.
                --}}
                @foreach ($categoriesWithGroups as $category)
                    <div x-show="categoryId == '{{ $category->id }}'" x-cloak>
                        <flux:select name="group_id" label="{{ __('Grupo') }}" x-bind:disabled="categoryId != '{{ $category->id }}'">
                            <flux:select.option value="">{{ __('Sin grupo específico') }}</flux:select.option>
                            @foreach ($category->groups as $group)
                                <flux:select.option value="{{ $group->id }}" :selected="old('group_id') == $group->id">
                                    {{ $group->name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endforeach

                <flux:input
                    name="name"
                    label="{{ __('Nombre del plantel') }}"
                    value="{{ old('name') }}"
                    placeholder="{{ __('Ej. Plantel A -- útil si el club tiene más de uno en esta categoría') }}"
                    required
                />

                <flux:input
                    name="short_name"
                    label="{{ __('Sigla (opcional)') }}"
                    value="{{ old('short_name') }}"
                    maxlength="10"
                />

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Crear plantel') }}</flux:button>
                    <flux:button :href="route('clubs.show', $club)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
