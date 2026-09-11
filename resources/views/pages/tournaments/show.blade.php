<x-layouts::app :title="$tournament->name">
    <div class="w-full space-y-8 animate-fade-in-up">
        <x-ui.page-header :title="$tournament->name" :subtitle="$tournament->description">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[
                    ['label' => __('Mis torneos'), 'href' => route('dashboard')],
                    ['label' => $tournament->name],
                ]" />
            </x-slot:breadcrumbs>

            <div class="mt-1 flex items-center gap-2">
                <flux:badge size="sm" :color="$tournament->status->color()">{{ $tournament->status->label() }}</flux:badge>

                @if ($tournament->season)
                    <flux:text class="text-sm text-zinc-500">{{ $tournament->season }}</flux:text>
                @endif
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2.5">
                <x-ui.stat-pill icon="rectangle-group" :value="$tournament->categories_count" :label="__('categorías')" color="cyan" />
                <x-ui.stat-pill icon="user-group" :value="$tournament->teams_count" :label="__('equipos')" color="amber" />
                <x-ui.stat-pill icon="calendar-days" :value="$tournament->matches_count" :label="__('partidos')" color="green" />
            </div>

            <x-slot:actions>
                <flux:button :href="route('tournaments.edit', $tournament)" variant="ghost" icon="pencil" wire:navigate>
                    {{ __('Editar') }}
                </flux:button>

                <x-ui.confirm-delete-form
                    :action="route('tournaments.destroy', $tournament)"
                    :heading="__('¿Eliminar este torneo?')"
                    :description="__('Se eliminarán también sus categorías, equipos y partidos. Esta acción no se puede deshacer.')"
                >
                    <flux:button variant="danger" icon="trash">{{ __('Eliminar') }}</flux:button>
                </x-ui.confirm-delete-form>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        @if ($tournament->slug)
            <x-ui.copy-link
                :url="route('public.tournaments.show', $tournament)"
                :label="__('Enlace público')"
            >
                <x-slot:description>
                    {{ __('Compártelo con los equipos o el público: cualquiera puede consultar el torneo con este enlace, sin iniciar sesión.') }}
                </x-slot:description>

                <x-slot:actions>
                    <form
                        method="POST"
                        action="{{ route('tournaments.regenerate-slug', $tournament) }}"
                        onsubmit="return confirm('{{ __('¿Regenerar el enlace público? El enlace actual dejará de funcionar de inmediato y tendrás que compartir el nuevo.') }}')"
                    >
                        @csrf
                        @method('PATCH')
                        <flux:button type="submit" variant="ghost" size="sm" icon="arrow-path">
                            {{ __('Regenerar enlace') }}
                        </flux:button>
                    </form>
                </x-slot:actions>
            </x-ui.copy-link>
        @else
            {{-- Defensive fallback: store() always sets a slug, so this
                 shouldn't happen in practice -- but if one somehow ended up
                 missing (a bad import, manual DB edit, etc.), the organizer
                 can fix it from here instead of needing tinker/DB access. --}}
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-500/30 p-5 dark:border-amber-400/30 glass-panel">
                <div class="flex items-center gap-2">
                    <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0 text-amber-500" />
                    <div>
                        <flux:heading size="sm">{{ __('Sin enlace público') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ __('Este torneo todavía no tiene un enlace público generado.') }}</flux:text>
                    </div>
                </div>

                <form method="POST" action="{{ route('tournaments.regenerate-slug', $tournament) }}">
                    @csrf
                    @method('PATCH')
                    <flux:button type="submit" variant="primary" size="sm" icon="link">
                        {{ __('Generar enlace público') }}
                    </flux:button>
                </form>
            </div>
        @endif

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Categorías') }}</flux:heading>

                <flux:button :href="route('tournaments.categories.create', $tournament)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Nueva categoría') }}
                </flux:button>
            </div>

            @if ($tournament->categories->isEmpty())
                <x-ui.empty-state icon="rectangle-group" :message="__('Este torneo todavía no tiene categorías.')">
                    <x-slot:action>
                        <flux:button :href="route('tournaments.categories.create', $tournament)" variant="primary" size="sm" icon="plus" wire:navigate>
                            {{ __('Nueva categoría') }}
                        </flux:button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <div class="flex flex-wrap justify-center gap-4">
                    @foreach ($tournament->categories->sortBy('order') as $category)
                        <x-ui.entity-card
                            :href="route('categories.show', $category)"
                            :title="$category->name"
                            icon="rectangle-group"
                            color="cyan"
                            :stats="[
                                trans_choice(':count equipo|:count equipos', $category->teams_count, ['count' => $category->teams_count]),
                                $category->uses_groups ? trans_choice(':count grupo|:count grupos', $category->groups_count, ['count' => $category->groups_count]) : __('Sin grupos'),
                            ]"
                        >
                            <x-slot:badges>
                                <flux:badge size="sm" :color="$category->status->color()">{{ $category->status->label() }}</flux:badge>
                            </x-slot:badges>
                        </x-ui.entity-card>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
