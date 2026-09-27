<x-layouts::app :title="__('Nuevo plantel')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up" x-data="{ categoryId: '{{ old('category_id') }}' }">
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
