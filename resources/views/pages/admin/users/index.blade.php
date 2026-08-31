<x-layouts::app :title="__('Usuarios')">
    <div class="w-full space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Usuarios') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Listado de todos los usuarios registrados en la plataforma.') }}</flux:text>
        </div>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Nombre') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Rol') }}</flux:table.column>
                <flux:table.column>{{ __('Torneos') }}</flux:table.column>
                <flux:table.column>{{ __('Estado') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($users as $user)
                    <flux:table.row>
                        <flux:table.cell>{{ $user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>{{ $user->role->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $user->tournaments_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$user->is_active ? 'green' : 'red'">
                                {{ $user->is_active ? __('Activo') : __('Inactivo') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($user->id !== auth()->id())
                                <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}">
                                    @csrf
                                    @method('PATCH')
                                    <flux:button type="submit" variant="ghost" size="sm">
                                        {{ $user->is_active ? __('Desactivar') : __('Activar') }}
                                    </flux:button>
                                </form>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</x-layouts::app>
