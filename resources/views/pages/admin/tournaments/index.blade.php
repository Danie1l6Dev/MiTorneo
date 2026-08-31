<x-layouts::app :title="__('Torneos')">
    <div class="w-full space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Torneos') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Todos los torneos creados en la plataforma.') }}</flux:text>
        </div>

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
                        <flux:table.cell>{{ $tournament->name }}</flux:table.cell>
                        <flux:table.cell>{{ $tournament->user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $tournament->season ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm">{{ $tournament->status->label() }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</x-layouts::app>
