<x-layouts::app :title="__('Editar partido')">
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Editar partido') }}</flux:heading>
            <flux:text class="mt-1">{{ $match->competitionPhase->name }}</flux:text>
        </div>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        <flux:card class="space-y-4">
            <div class="flex items-center justify-center gap-4">
                <flux:heading size="lg">{{ $match->homeTeam->name }}</flux:heading>
                <flux:heading size="lg">{{ $match->home_score ?? '-' }} : {{ $match->away_score ?? '-' }}</flux:heading>
                <flux:heading size="lg">{{ $match->awayTeam->name }}</flux:heading>
            </div>

            <div class="flex justify-center">
                <flux:badge size="sm" :color="$match->status === \App\Enums\MatchStatus::Finished ? 'green' : 'zinc'">
                    {{ __('Estado: :status', ['status' => $match->status->label()]) }}
                </flux:badge>
            </div>

            <form method="POST" action="{{ route('matches.result.update', $match) }}" class="flex flex-wrap items-end justify-center gap-4">
                @csrf
                @method('PATCH')

                <flux:input
                    name="home_score"
                    type="number"
                    min="0"
                    label="{{ $match->homeTeam->name }}"
                    value="{{ old('home_score', $match->home_score ?? '') }}"
                    class="w-24"
                />
                <flux:input
                    name="away_score"
                    type="number"
                    min="0"
                    label="{{ $match->awayTeam->name }}"
                    value="{{ old('away_score', $match->away_score ?? '') }}"
                    class="w-24"
                />

                <flux:button type="submit" variant="primary">{{ __('Registrar resultado') }}</flux:button>
            </form>
        </flux:card>

        <flux:separator />

        <flux:heading size="sm">{{ __('Detalles del partido') }}</flux:heading>

        <form method="POST" action="{{ route('matches.update', $match) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @include('pages.matches._fields')

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Guardar cambios') }}</flux:button>
                <flux:button :href="route('phases.show', $match->competitionPhase)" variant="ghost" wire:navigate>{{ __('Cancelar') }}</flux:button>
            </div>
        </form>

        <flux:separator />

        <form method="POST" action="{{ route('matches.destroy', $match) }}" onsubmit="return confirm('{{ __('¿Eliminar este partido?') }}')">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger" icon="trash">{{ __('Eliminar partido') }}</flux:button>
        </form>
    </div>
</x-layouts::app>
