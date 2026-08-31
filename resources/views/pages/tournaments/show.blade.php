<x-layouts::app :title="$tournament->name">
    <div class="w-full space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:button :href="route('tournaments.index')" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
                    {{ __('Torneos') }}
                </flux:button>

                <flux:heading size="xl" class="mt-2">{{ $tournament->name }}</flux:heading>

                <div class="mt-1 flex items-center gap-2">
                    <flux:badge size="sm">{{ $tournament->status->label() }}</flux:badge>

                    @if ($tournament->season)
                        <flux:text class="text-sm text-zinc-500">{{ $tournament->season }}</flux:text>
                    @endif
                </div>

                @if ($tournament->description)
                    <flux:text class="mt-3 max-w-2xl">{{ $tournament->description }}</flux:text>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <flux:button :href="route('tournaments.edit', $tournament)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <form method="POST" action="{{ route('tournaments.destroy', $tournament) }}" onsubmit="return confirm('{{ __('¿Eliminar este torneo? Se eliminarán también sus categorías, equipos y partidos.') }}')">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </form>
            </div>
        </div>

        <flux:separator />

        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Categorías') }}</flux:heading>

            <flux:button :href="route('tournaments.categories.create', $tournament)" variant="primary" size="sm" icon="plus" wire:navigate>
                {{ __('Nueva categoría') }}
            </flux:button>
        </div>

        @if ($tournament->categories->isEmpty())
            <flux:text class="text-zinc-500">{{ __('Este torneo todavía no tiene categorías.') }}</flux:text>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($tournament->categories->sortBy('order') as $category)
                    <a href="{{ route('categories.show', $category) }}" wire:navigate class="block">
                        <flux:card class="h-full hover:border-zinc-300 dark:hover:border-white/20">
                            <flux:heading>{{ $category->name }}</flux:heading>
                        </flux:card>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts::app>
