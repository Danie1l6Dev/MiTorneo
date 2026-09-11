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
                <x-ui.stat-card :label="__('Pendientes')" :value="$pendingSanctions->count()" icon="clock" color="amber" />
                <x-ui.stat-card :label="__('Activas')" :value="$activeSanctions->count()" icon="shield-exclamation" color="red" />
                <x-ui.stat-card :label="__('Cumplidas')" :value="$fulfilledSanctions->count()" icon="check-circle" color="green" />
            </div>

            {{-- Ordered todo -> en curso -> hecho: what needs the organizer's
                 attention first, then what's actively being served, then
                 what's already resolved and finished -- same priority the
                 stat cards above already read left to right. --}}
            @if ($pendingSanctions->isNotEmpty())
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Faltan por resolver') }}</flux:heading>

                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                        @foreach ($pendingSanctions as $sanction)
                            <x-ui.sanction-row :sanction="$sanction" />
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($activeSanctions->isNotEmpty())
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Ya tienen resolución') }}</flux:heading>

                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                        @foreach ($activeSanctions as $sanction)
                            <x-ui.sanction-row :sanction="$sanction" />
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($fulfilledSanctions->isNotEmpty())
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Ya cumplidas') }}</flux:heading>

                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200 dark:divide-white/5 dark:border-white/10 glass-panel">
                        @foreach ($fulfilledSanctions as $sanction)
                            <x-ui.sanction-row :sanction="$sanction" />
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-layouts::app>
