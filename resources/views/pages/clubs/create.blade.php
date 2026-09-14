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
                                @if ($category->uses_groups)
                                    <div class="rounded-xl border border-zinc-200 dark:border-white/10" x-data="{ open: {{ old('group_selections.'.$category->id) ? 'true' : 'false' }} }">
                                        <button type="button" @click="open = !open" class="flex w-full cursor-pointer items-center justify-between gap-2 px-3 py-2.5 text-left">
                                            <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ $category->name }}</span>
                                            <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 text-zinc-400 transition-transform duration-200" x-bind:class="open && 'rotate-180'" />
                                        </button>

                                        <div x-show="open" x-collapse.duration.200ms x-cloak>
                                            <div class="space-y-1 border-t border-zinc-100 px-3 py-3 dark:border-white/5">
                                                @if ($category->groups->isEmpty())
                                                    <flux:text class="text-xs text-zinc-400">{{ __('Esta categoría todavía no tiene grupos definidos.') }}</flux:text>
                                                @else
                                                    @php $selected = (array) old("group_selections.{$category->id}", []); @endphp
                                                    @foreach ($category->groups as $group)
                                                        <flux:checkbox
                                                            name="group_selections[{{ $category->id }}][]"
                                                            value="{{ $group->id }}"
                                                            label="{{ $group->name }}"
                                                            :checked="in_array($group->id, $selected)"
                                                        />
                                                    @endforeach
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <div class="rounded-xl border border-zinc-200 px-3 py-2.5 dark:border-white/10">
                                        <flux:checkbox
                                            name="category_ids[]"
                                            value="{{ $category->id }}"
                                            label="{{ $category->name }}"
                                            :checked="in_array($category->id, (array) old('category_ids', []))"
                                        />
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        @error('group_selections')
                            <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                        @enderror
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
