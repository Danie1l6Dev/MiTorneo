<x-layouts::app :title="__('Agregar categorías')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Agregar categorías')" :subtitle="$tournament->name" />

        @if ($availableCategories->isEmpty())
            @if ($hasAnyCategories)
                <x-ui.empty-state icon="rectangle-stack" :message="__('Ya agregaste todas tus categorías actuales a este torneo. Si quieres agregar otra, ve a la sección de Categorías para crearla primero.')" />
            @else
                <x-ui.empty-state icon="rectangle-stack" :message="__('Todavía no creaste ninguna categoría.')">
                    <x-slot:action>
                        <flux:button :href="route('categories.create')" variant="primary" size="sm" icon="plus" wire:navigate>
                            {{ __('Crear categoría') }}
                        </flux:button>
                    </x-slot:action>
                </x-ui.empty-state>
            @endif
        @else
            <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
                <form method="POST" action="{{ route('tournaments.global-categories.store', $tournament) }}" class="space-y-6">
                    @csrf

                    <div class="space-y-2">
                        <flux:label>{{ __('Elige las categorías de tu catálogo que juegan en este torneo') }}</flux:label>

                        @foreach ($availableCategories as $category)
                            <div class="rounded-xl border border-zinc-200 px-3 py-2.5 dark:border-white/10">
                                <flux:checkbox
                                    name="category_ids[]"
                                    value="{{ $category->id }}"
                                    label="{{ $category->name }}"
                                    :checked="in_array($category->id, (array) old('category_ids', []))"
                                />
                            </div>
                        @endforeach

                        @error('category_ids')
                            <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                        @enderror
                    </div>

                    <div class="flex items-center gap-3">
                        <flux:button type="submit" variant="primary">{{ __('Agregar') }}</flux:button>
                        <flux:button :href="route('tournaments.show', $tournament)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-layouts::app>
