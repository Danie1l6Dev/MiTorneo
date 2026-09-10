<x-layouts::app :title="__('Sanciones')">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="__('Sanciones')" :subtitle="__('Sanciones disciplinarias generadas a partir de las tarjetas registradas en tus partidos.')" />

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        @if ($sanctions->isEmpty())
            <x-ui.empty-state icon="shield-exclamation" :message="__('Todavía no hay sanciones registradas. Se generan automáticamente cuando registrás una doble amarilla o una roja directa en un partido.')" />
        @else
            <div class="mx-auto grid grid-cols-3 gap-4 sm:gap-5 lg:max-w-2xl">
                <x-ui.stat-card :label="__('Pendientes')" :value="$sanctions->where('status', \App\Enums\SanctionStatus::Pending)->count()" icon="clock" color="amber" />
                <x-ui.stat-card :label="__('Activas')" :value="$sanctions->filter->isActive()->count()" icon="shield-exclamation" color="red" />
                <x-ui.stat-card :label="__('Cumplidas')" :value="$sanctions->filter->isFulfilled()->count()" icon="check-circle" color="green" />
            </div>

            <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                @foreach ($sanctions as $sanction)
                    <a href="{{ route('sanctions.show', $sanction) }}" wire:navigate class="flex items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-zinc-50 dark:hover:bg-white/5">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $sanction->subjectLabel() }}</div>
                            <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-white/50">
                                {{ $sanction->team->name }} &middot; {{ $sanction->team->tournament->name }} &middot; {{ $sanction->match->category->name }}
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <flux:badge size="sm" :color="$sanction->type->color()">{{ $sanction->type->label() }}</flux:badge>
                            <flux:badge size="sm" :color="$sanction->status->color()">{{ $sanction->stateLabel() }}</flux:badge>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts::app>
