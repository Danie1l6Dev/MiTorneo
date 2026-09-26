@php
    $label = fn ($team) => $team->club?->name ?? $team->name;
    $age = $player->birth_date?->age;
    $currentEntries = collect($timeline)->where('current', true);
    $jerseys = $currentEntries->pluck('jersey')->filter(fn ($jersey) => $jersey !== null)->unique()->values();
@endphp

<x-layouts::app :title="$player->full_name">
    <div class="w-full space-y-6 animate-fade-in-up">
        {{-- Header: who the player is and where they play now -- the only place the current
             club/plantel is spelled out (everything below refers to the past or to numbers). --}}
        <x-ui.page-header :title="$player->full_name" :subtitle="$player->document_number ? __('Documento: :document', ['document' => $player->document_number]) : __('Sin documento registrado')">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Buscar jugador'), 'href' => route('players.index')],
                    ['label' => $player->full_name],
                ]" />
            </x-slot:breadcrumbs>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                @forelse ($currentTeams as $team)
                    <flux:badge size="sm" color="green" icon="shield-check">
                        {{ $label($team) }} · {{ $team->category->name }}@if ($team->group) · {{ $team->group->name }}@endif
                    </flux:badge>
                @empty
                    <flux:badge size="sm" color="zinc">{{ __('Sin plantel') }}</flux:badge>
                @endforelse

                @unless ($player->is_active)
                    <flux:badge size="sm" color="zinc">{{ __('Inactivo') }}</flux:badge>
                @endunless
            </div>

            <x-slot:actions>
                <flux:button :href="route('players.edit', $player)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar ficha') }}
                </flux:button>
            </x-slot:actions>
        </x-ui.page-header>

        <div class="grid gap-6 lg:grid-cols-12 lg:items-start">
            {{-- Left column: the numbers and the personal data --}}
            <aside class="space-y-6 lg:col-span-4 xl:col-span-3">
                <div class="rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                    <flux:heading size="sm" class="mb-4">{{ __('Resumen') }}</flux:heading>

                    <div class="grid grid-cols-3 gap-3 lg:grid-cols-2">
                        @foreach ([
                            ['label' => __('Goles'), 'value' => $totals['goals'], 'color' => 'text-green-500'],
                            ['label' => __('Asistencias'), 'value' => $totals['assists'], 'color' => 'text-cyan-500'],
                            ['label' => __('Amarillas'), 'value' => $totals['yellow_cards'], 'color' => 'text-amber-500'],
                            ['label' => __('Rojas'), 'value' => $totals['red_cards'], 'color' => 'text-red-500'],
                            ['label' => __('Sanciones'), 'value' => $totals['sanctions'], 'color' => 'text-red-500'],
                            ['label' => __('Torneos'), 'value' => $totals['tournaments'], 'color' => 'text-zinc-900 dark:text-white'],
                        ] as $stat)
                            <div class="rounded-xl bg-zinc-100/70 px-3 py-3 text-center dark:bg-white/5">
                                <div class="font-display text-3xl font-bold leading-none tabular-nums {{ $stat['color'] }}">{{ $stat['value'] }}</div>
                                <div class="mt-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50">{{ $stat['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                    <flux:heading size="sm" class="mb-3">{{ __('Datos personales') }}</flux:heading>

                    <dl class="divide-y divide-zinc-100 text-sm dark:divide-white/5">
                        <div class="flex items-baseline justify-between gap-3 py-2.5">
                            <dt class="text-zinc-500 dark:text-white/50">{{ __('Nacimiento') }}</dt>
                            <dd class="text-right font-medium text-zinc-900 dark:text-white">
                                @if ($player->birth_date)
                                    {{ $player->birth_date->format('d/m/Y') }}
                                    <span class="font-normal text-zinc-500 dark:text-white/50">· {{ trans_choice(':count año|:count años', $age, ['count' => $age]) }}</span>
                                @else
                                    <span class="font-normal text-zinc-400 dark:text-white/40">{{ __('Sin registrar') }}</span>
                                @endif
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 py-2.5">
                            <dt class="text-zinc-500 dark:text-white/50">{{ __('Género') }}</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">
                                {{ $player->gender?->label() ?? '' }}
                                @unless ($player->gender)
                                    <span class="font-normal text-zinc-400 dark:text-white/40">{{ __('Sin registrar') }}</span>
                                @endunless
                            </dd>
                        </div>

                        @if ($jerseys->isNotEmpty())
                            <div class="flex items-baseline justify-between gap-3 py-2.5">
                                <dt class="text-zinc-500 dark:text-white/50">{{ trans_choice('Dorsal|Dorsales', $jerseys->count()) }}</dt>
                                <dd class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ $jerseys->implode(' · ') }}</dd>
                            </div>
                        @endif

                        <div class="flex items-baseline justify-between gap-3 py-2.5">
                            <dt class="text-zinc-500 dark:text-white/50">{{ __('Registrado') }}</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">{{ $player->created_at?->format('d/m/Y') ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>
            </aside>

            {{-- Right column: what the player has done --}}
            <div class="space-y-6 lg:col-span-8 xl:col-span-9">
                <div class="rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                    <flux:heading size="sm" class="mb-4">{{ __('Torneos jugados') }}</flux:heading>

                    @if ($tournaments === [])
                        <flux:text class="text-zinc-500 dark:text-white/60">{{ __('Este jugador todavía no aparece en ningún torneo.') }}</flux:text>
                    @else
                        <div class="-mx-5 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-white/10 dark:text-white/50">
                                        <th class="px-5 py-2.5">{{ __('Torneo') }}</th>
                                        <th class="px-2 py-2.5">{{ __('Plantel') }}</th>
                                        <th class="px-2 py-2.5 text-center" title="{{ __('Goles') }}">{{ __('G') }}</th>
                                        <th class="px-2 py-2.5 text-center" title="{{ __('Asistencias') }}">{{ __('A') }}</th>
                                        <th class="px-2 py-2.5 text-center" title="{{ __('Tarjetas amarillas') }}">{{ __('TA') }}</th>
                                        <th class="px-2 py-2.5 text-center" title="{{ __('Tarjetas rojas') }}">{{ __('TR') }}</th>
                                        <th class="px-5 py-2.5 text-right">{{ __('Último evento') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($tournaments as $row)
                                        <tr class="border-b border-zinc-100 last:border-0 dark:border-white/5">
                                            <td class="px-5 py-3 font-medium text-zinc-800 dark:text-white">
                                                {{ $row['tournament']->name }}
                                                @if ($row['tournament']->season)
                                                    <span class="font-normal text-zinc-400 dark:text-white/40">· {{ $row['tournament']->season }}</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-3 text-zinc-600 dark:text-white/70">
                                                {{ $label($row['team']) }} · {{ $row['team']->category->name }}
                                                @unless ($row['current'])
                                                    <flux:badge size="sm" color="zinc" class="ms-1">{{ __('Anterior') }}</flux:badge>
                                                @endunless
                                            </td>
                                            <td class="px-2 py-3 text-center font-display font-bold tabular-nums text-zinc-900 dark:text-white">{{ $row['goals'] }}</td>
                                            <td class="px-2 py-3 text-center font-display font-bold tabular-nums text-zinc-900 dark:text-white">{{ $row['assists'] }}</td>
                                            <td class="px-2 py-3 text-center font-display font-bold tabular-nums text-amber-500">{{ $row['yellow_cards'] }}</td>
                                            <td class="px-2 py-3 text-center font-display font-bold tabular-nums text-red-500">{{ $row['red_cards'] }}</td>
                                            <td class="px-5 py-3 text-right whitespace-nowrap text-zinc-500 dark:text-white/60">{{ $row['last_at']?->format('d/m/Y') ?? __('Sin eventos') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <flux:text class="mt-3 text-xs text-zinc-500 dark:text-white/50">
                            {{ __('Salen de los eventos registrados en cada partido. El sistema no guarda quién jugó cada partido, así que no hay conteo de partidos jugados.') }}
                        </flux:text>
                    @endif
                </div>

                <div class="grid gap-6 xl:grid-cols-2 xl:items-start">
                    {{-- Sanctions --}}
                    <div class="rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                        <flux:heading size="sm" class="mb-4">{{ __('Historial de sanciones') }}</flux:heading>

                        @if ($sanctions->isEmpty())
                            <flux:text class="text-zinc-500 dark:text-white/60">{{ __('Este jugador no tiene sanciones registradas.') }}</flux:text>
                        @else
                            <div class="space-y-3">
                                @foreach ($sanctions as $sanction)
                                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-white/10">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <flux:badge size="sm" :color="$sanction->type->color()">{{ $sanction->type->label() }}</flux:badge>
                                                <flux:badge size="sm" :color="$sanction->status->color()">{{ $sanction->status->label() }}</flux:badge>
                                            </div>

                                            <flux:link :href="route('sanctions.show', $sanction)" wire:navigate class="text-sm font-medium">
                                                {{ __('Ver') }}
                                            </flux:link>
                                        </div>

                                        <div class="mt-2 text-sm text-zinc-700 dark:text-white/80">
                                            {{ $sanction->match->homeTeam?->name ?? __('Por definir') }}
                                            <span class="text-zinc-400 dark:text-white/40">vs</span>
                                            {{ $sanction->match->awayTeam?->name ?? __('Por definir') }}
                                        </div>

                                        <div class="mt-0.5 text-xs text-zinc-500 dark:text-white/50">
                                            {{ $sanction->match->tournament->name }} · {{ $sanction->match->category->name }}
                                            · {{ ($sanction->match->scheduled_at ?? $sanction->created_at)->format('d/m/Y') }}
                                        </div>

                                        <div class="mt-2 text-sm text-zinc-600 dark:text-white/70">
                                            {{ $sanction->stateLabel() }}
                                            @if ($sanction->matches_banned !== null)
                                                · {{ trans_choice(':count fecha de suspensión|:count fechas de suspensión', $sanction->matches_banned, ['count' => $sanction->matches_banned]) }}
                                            @endif
                                            @if ($sanction->resolved_at)
                                                · {{ __('Resuelta el :date', ['date' => $sanction->resolved_at->format('d/m/Y')]) }}
                                            @endif
                                        </div>

                                        @if ($sanction->resolution_notes)
                                            <div class="mt-2 text-sm italic text-zinc-500 dark:text-white/50">{{ $sanction->resolution_notes }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Clubs and planteles timeline --}}
                    <div class="rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
                        <flux:heading size="sm">{{ __('Historial de clubes y planteles') }}</flux:heading>

                        <flux:text class="mb-4 mt-1 text-xs text-zinc-500 dark:text-white/50">
                            <span class="font-semibold">{{ __('Historial deducido.') }}</span>
                            {{ __('El sistema no guarda cuándo un jugador entró o salió de un plantel: sale del plantel actual y de los que aparecen en sus goles, tarjetas y sanciones.') }}
                        </flux:text>

                        @if ($timeline === [])
                            <flux:text class="text-zinc-500 dark:text-white/60">{{ __('No hay clubes ni planteles que mostrar para este jugador.') }}</flux:text>
                        @else
                            <ol class="relative ms-2 space-y-5 border-s border-zinc-200 ps-5 dark:border-white/10">
                                @foreach ($timeline as $entry)
                                    <li class="relative">
                                        <span @class([
                                            'absolute -start-[1.65rem] top-1.5 size-3 rounded-full ring-4 ring-white dark:ring-zinc-900',
                                            'bg-green-500' => $entry['current'],
                                            'bg-zinc-400 dark:bg-white/30' => ! $entry['current'],
                                        ])></span>

                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium text-zinc-900 dark:text-white">{{ $label($entry['team']) }}</span>
                                            <flux:badge size="sm" :color="$entry['current'] ? 'green' : 'zinc'">{{ $entry['current'] ? __('Actual') : __('Anterior') }}</flux:badge>
                                        </div>

                                        <div class="text-sm text-zinc-500 dark:text-white/60">
                                            {{ $entry['team']->category->name }}@if ($entry['team']->group) · {{ $entry['team']->group->name }}@endif
                                            @if ($entry['jersey'] !== null)
                                                · {{ __('Dorsal') }} {{ $entry['jersey'] }}
                                            @endif
                                        </div>

                                        @if ($entry['tournaments'] !== [])
                                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-white/50">
                                                {{ implode(' · ', $entry['tournaments']) }}
                                                @if ($entry['first_at'])
                                                    · {{ $entry['first_at']->format('d/m/Y') }}@if (! $entry['first_at']->isSameDay($entry['last_at'])) — {{ $entry['last_at']->format('d/m/Y') }}@endif
                                                @endif
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts::app>
