<x-layouts::app :title="__('Categorías')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Categorías')" :subtitle="__('Tu catálogo de categorías, reutilizable en cualquiera de tus torneos.')">
            <x-slot:actions>
                <flux:button :href="route('categories.create')" variant="primary" icon="plus" wire:navigate>
                    {{ __('Nueva categoría') }}
                </flux:button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @if ($categories->isEmpty())
            <x-ui.empty-state icon="rectangle-stack" :message="__('Todavía no has creado ninguna categoría.')">
                <x-slot:action>
                    <flux:button :href="route('categories.create')" variant="primary" size="sm" icon="plus" wire:navigate>
                        {{ __('Crear categoría') }}
                    </flux:button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="flex flex-wrap justify-center gap-4">
                @foreach ($categories as $category)
                    <x-ui.entity-card
                        :href="route('categories.show', $category)"
                        :title="$category->name"
                        icon="rectangle-stack"
                        :color="$category->status->color()"
                        :stats="[
                            $category->birth_year_from && $category->birth_year_to
                                ? __(':from–:to', ['from' => $category->birth_year_from, 'to' => $category->birth_year_to])
                                : __('Sin rango de edad definido'),
                            trans_choice(':count plantel|:count planteles', $category->teams_count, ['count' => $category->teams_count]),
                        ]"
                        :cta="__('Ver categoría')"
                    >
                        <x-slot:badges>
                            <flux:badge size="sm" :color="$category->status->color()">{{ $category->status->label() }}</flux:badge>

                            @if ($category->uses_groups)
                                <flux:badge size="sm" color="zinc">{{ __('Usa grupos') }}</flux:badge>
                            @endif
                        </x-slot:badges>
                    </x-ui.entity-card>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts::app>
