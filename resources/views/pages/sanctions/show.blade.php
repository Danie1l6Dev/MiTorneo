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
                    <dd class="font-medium text-zinc-800 dark:text-white">{{ $sanction->match->tournament->name }} &middot; {{ $sanction->match->category->name }}</dd>
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

                    @if ($sanction->resolution_pdf_path || $sanction->resolution_notes || $pdfUploadsEnabled)
                        <div class="col-span-2 space-y-3">
                            <dt class="text-zinc-500 dark:text-white/50">{{ __('Resolución del comité') }}</dt>
                            <dd class="space-y-3">
                                @if ($sanction->resolution_pdf_path)
                                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-white/10">
                                        <embed src="{{ $sanction->resolutionPdfUrl() }}" type="application/pdf" class="h-[80vh] w-full" />
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:button href="{{ $sanction->resolutionPdfUrl() }}" download="{{ $sanction->resolutionPdfDownloadName() }}" icon="arrow-down-tray" size="sm">
                                            {{ __('Descargar PDF') }}
                                        </flux:button>

                                        <x-ui.confirm-delete-form :action="route('sanctions.resolution-pdf.destroy', $sanction)" :heading="__('¿Quitar el PDF de la resolución?')" :description="__('Podés subir uno nuevo después si hace falta.')" :confirm-label="__('Quitar PDF')">
                                            <flux:button variant="danger" icon="trash" size="sm">
                                                {{ __('Quitar PDF') }}
                                            </flux:button>
                                        </x-ui.confirm-delete-form>
                                    </div>
                                @elseif ($sanction->resolution_notes)
                                    <p class="text-sm font-medium text-zinc-800 dark:text-white">{{ $sanction->resolution_notes }}</p>
                                @endif

                                @if ($pdfUploadsEnabled)
                                    <form method="POST" action="{{ route('sanctions.resolution-pdf.update', $sanction) }}" enctype="multipart/form-data" @class(['space-y-3', 'border-t border-zinc-200 pt-3 dark:border-white/10' => $sanction->resolution_pdf_path || $sanction->resolution_notes])>
                                        @csrf
                                        @method('PATCH')
                                        <flux:input type="file" name="resolution_pdf" :label="$sanction->resolution_pdf_path ? __('Reemplazar PDF') : __('Adjuntar PDF')" accept="application/pdf" />
                                        <div class="flex justify-end">
                                            <flux:button type="submit" size="sm">{{ $sanction->resolution_pdf_path ? __('Reemplazar') : __('Adjuntar') }}</flux:button>
                                        </div>
                                    </form>
                                @endif
                            </dd>
                        </div>
                    @endif
                @endif
            </dl>
        </div>

        @if ($sanction->isResolved() && $sanction->type === \App\Enums\SanctionType::RedCard)
            <div class="flex justify-end">
                <x-ui.confirm-delete-form :action="route('sanctions.reset', $sanction)" method="PATCH" icon="arrow-path" variant="warning" :heading="__('¿Restablecer esta sanción?')" :description="__('Se borran las fechas, el motivo, la multa y el PDF de la resolución, y la sanción vuelve a quedar pendiente. La tarjeta del partido no se modifica.')" :confirm-label="__('Restablecer sanción')">
                    <flux:button icon="arrow-path" size="sm">
                        {{ __('Restablecer sanción') }}
                    </flux:button>
                </x-ui.confirm-delete-form>
            </div>
        @endif

        @if ($sanction->isPending())
            <div class="space-y-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                <flux:heading size="lg">{{ __('Resolución del Comité Directivo') }}</flux:heading>

                <flux:text class="text-sm">{{ __('Una roja directa no tiene una duración asumida: indica cuántas fechas de sanción corresponden según lo resuelto.') }}</flux:text>

                <form method="POST" action="{{ route('sanctions.resolve', $sanction) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    <flux:input type="number" name="matches_banned" label="{{ __('Cantidad de fechas') }}" value="{{ old('matches_banned') }}" min="1" required autofocus />

                    @if ($sanction->coach_id !== null)
                        <flux:input type="number" step="0.01" name="fine_amount" label="{{ __('Multa económica (opcional)') }}" value="{{ old('fine_amount') }}" min="0" />
                    @endif

                    @if ($pdfUploadsEnabled)
                        <flux:input type="file" name="resolution_pdf" label="{{ __('PDF de la resolución del comité (opcional)') }}" accept="application/pdf" />
                    @else
                        <flux:textarea name="resolution_notes" label="{{ __('Motivo / observación (opcional)') }}" rows="3">{{ old('resolution_notes') }}</flux:textarea>
                    @endif

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
