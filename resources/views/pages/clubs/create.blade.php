<x-layouts::app :title="__('Nuevo club')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Nuevo club')" :subtitle="__('Puedes elegir de una vez en qué categorías juega -- o dejarlo para después.')" />

        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-white/10 glass-panel sm:p-8">
            <form method="POST" action="{{ route('clubs.store') }}" class="space-y-6">
                @csrf

                @include('pages.clubs._fields')

                @if ($categories->isNotEmpty())
                    <div class="space-y-3">
                        <flux:label>{{ __('Categorías en las que juega (opcional)') }}</flux:label>
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('Por cada una se crea de una vez un plantel con el nombre del club -- puedes agregar más planteles o cambiarles el nombre después.') }}
                        </flux:text>

                        <div class="space-y-2">
                            @foreach ($categories as $category)
                                <div class="rounded-xl border border-zinc-200 px-3 py-2.5 dark:border-white/10">
                                    <flux:checkbox
                                        name="category_ids[]"
                                        value="{{ $category->id }}"
                                        label="{{ $category->name }}"
                                        :checked="in_array($category->id, (array) old('category_ids', []))"
                                    />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Crear club') }}</flux:button>
                    <flux:button :href="route('clubs.index')" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::app>
