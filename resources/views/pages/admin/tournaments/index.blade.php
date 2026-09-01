<x-layouts::app :title="__('Torneos')">
    <div class="w-full space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Torneos')" :subtitle="__('Todos los torneos creados en la plataforma.')" />

        <div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-white/10 glass-panel">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Nombre') }}</flux:table.column>
                    <flux:table.column>{{ __('Propietario') }}</flux:table.column>
                    <flux:table.column>{{ __('Temporada') }}</flux:table.column>
                    <flux:table.column>{{ __('Estado') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($tournaments as $tournament)
                        <flux:table.row>
                            <flux:table.cell class="font-medium text-zinc-800 dark:text-white">{{ $tournament->name }}</flux:table.cell>
                            <flux:table.cell>{{ $tournament->user->name }}</flux:table.cell>
                            <flux:table.cell>{{ $tournament->season ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$tournament->status->color()">{{ $tournament->status->label() }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
</x-layouts::app>
