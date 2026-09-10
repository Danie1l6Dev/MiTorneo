<x-layouts::app :title="$sanction->subjectLabel()">
    <div class="mx-auto w-full max-w-2xl space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$sanction->subjectLabel()" :subtitle="$sanction->type->label()">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Sanciones'), 'href' => route('sanctions.index')],
                    ['label' => $sanction->subjectLabel()],
                ]" />
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
        @endif

        <div class="space-y-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
            <div class="flex items-center justify-between">
                <flux:badge size="sm" :color="$sanction->type->color()">{{ $sanction->type->label() }}</flux:badge>
                <flux:badge size="sm" :color="$sanction->status->color()">{{ $sanction->stateLabel() }}</flux:badge>
            </div>

            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-zinc-500 dark:text-white/50">{{ __('Equipo') }}</dt>
                    <dd class="font-medium text-zinc-800 dark:text-white">{{ $sanction->team->name }}</dd>
                </div>

                <div>
                    <dt class="text-zinc-500 dark:text-white/50">{{ __('Torneo / categoría') }}</dt>
                    <dd class="font-medium text-zinc-800 dark:text-white">{{ $sanction->team->tournament->name }} &middot; {{ $sanction->match->category->name }}</dd>
                </div>

                <div class="col-span-2">
                    <dt class="text-zinc-500 dark:text-white/50">{{ __('Partido de origen') }}</dt>
                    <dd class="font-medium text-zinc-800 dark:text-white">
                        <flux:link :href="route('matches.edit', $sanction->match)" wire:navigate>
                            {{ $sanction->match->homeTeam?->name ?? __('Por definir') }}
                            <span class="text-zinc-400 dark:text-white/40">vs</span>
                            {{ $sanction->match->awayTeam?->name ?? __('Por definir') }}
                        </flux:link>
                    </dd>
                </div>

                @if ($sanction->isResolved())
                    <div>
                        <dt class="text-zinc-500 dark:text-white/50">{{ __('Fechas de sanción') }}</dt>
                        <dd class="font-medium text-zinc-800 dark:text-white">{{ $sanction->matchesServedCount() }} / {{ $sanction->matches_banned }}</dd>
                    </div>

                    <div>
                        <dt class="text-zinc-500 dark:text-white/50">{{ __('Resuelta el') }}</dt>
                        <dd class="font-medium text-zinc-800 dark:text-white">{{ $sanction->resolved_at?->format('d/m/Y') }}</dd>
                    </div>

                    @if ($sanction->coach_id !== null)
                        <div>
                            <dt class="text-zinc-500 dark:text-white/50">{{ __('Multa económica') }}</dt>
                            <dd class="font-medium text-zinc-800 dark:text-white">
                                {{ $sanction->fine_amount !== null ? '$'.number_format((float) $sanction->fine_amount, 2) : __('Sin multa') }}
                            </dd>
                        </div>
                    @endif

                    @if ($sanction->resolution_notes)
                        <div class="col-span-2">
                            <dt class="text-zinc-500 dark:text-white/50">{{ __('Motivo / observación') }}</dt>
                            <dd class="font-medium text-zinc-800 dark:text-white">{{ $sanction->resolution_notes }}</dd>
                        </div>
                    @endif
                @endif
            </dl>
        </div>

        @if ($sanction->isPending())
            <div class="space-y-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                <flux:heading size="lg">{{ __('Resolución del Comité Directivo') }}</flux:heading>

                <flux:text class="text-sm">{{ __('Una roja directa no tiene una duración asumida: indicá cuántas fechas de sanción corresponden según lo resuelto.') }}</flux:text>

                <form method="POST" action="{{ route('sanctions.resolve', $sanction) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    <flux:input type="number" name="matches_banned" label="{{ __('Cantidad de fechas') }}" value="{{ old('matches_banned') }}" min="1" required autofocus />

                    @if ($sanction->coach_id !== null)
                        <flux:input type="number" step="0.01" name="fine_amount" label="{{ __('Multa económica (opcional)') }}" value="{{ old('fine_amount') }}" min="0" />
                    @endif

                    <flux:textarea name="resolution_notes" label="{{ __('Motivo / observación (opcional)') }}" rows="3">{{ old('resolution_notes') }}</flux:textarea>

                    <flux:button type="submit" variant="primary">{{ __('Resolver sanción') }}</flux:button>
                </form>
            </div>
        @else
            @php $servingMatches = $sanction->servingWindowMatches(); @endphp

            @if ($servingMatches->isNotEmpty())
                <div class="space-y-2 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                    <flux:heading size="lg">{{ __('Fechas de la sanción') }}</flux:heading>

                    <flux:text class="text-sm">{{ __('Se calculan automáticamente a partir de los próximos partidos del equipo -- cada uno se marca cumplido apenas finaliza.') }}</flux:text>

                    <div class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach ($servingMatches as $servingMatch)
                            <a href="{{ route('matches.edit', $servingMatch) }}" wire:navigate class="flex items-center justify-between gap-3 py-2.5 transition-colors hover:opacity-80">
                                <div class="min-w-0 text-sm">
                                    <span class="text-zinc-800 dark:text-white">{{ $servingMatch->homeTeam?->name ?? __('Por definir') }}</span>
                                    <span class="text-zinc-400 dark:text-white/40">vs</span>
                                    <span class="text-zinc-800 dark:text-white">{{ $servingMatch->awayTeam?->name ?? __('Por definir') }}</span>
                                </div>

                                @if ($servingMatch->status === \App\Enums\MatchStatus::Finished)
                                    <flux:badge size="sm" color="green">{{ __('Cumplida') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" :color="$servingMatch->status->color()">{{ $servingMatch->status->label() }}</flux:badge>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-layouts::app>
