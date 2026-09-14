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
                <x-ui.stat-pill icon="rectangle-group" :value="$tournament->global_categories_count" :label="__('categorías')" color="cyan" />
                <x-ui.stat-pill icon="user-group" :value="$tournament->global_teams_count" :label="__('equipos')" color="amber" />
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

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-circle" :heading="session('error')" />
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
                    <x-ui.confirm-delete-form
                        :action="route('tournaments.regenerate-slug', $tournament)"
                        method="PATCH"
                        variant="warning"
                        icon="arrow-path"
                        :heading="__('¿Regenerar el enlace público?')"
                        :description="__('El enlace actual dejará de funcionar de inmediato y tendrás que compartir el nuevo.')"
                        :confirm-label="__('Regenerar enlace')"
                    >
                        <flux:button variant="ghost" size="sm" icon="arrow-path">
                            {{ __('Regenerar enlace') }}
                        </flux:button>
                    </x-ui.confirm-delete-form>
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

                <flux:button :href="route('tournaments.global-categories.create', $tournament)" variant="primary" size="sm" icon="plus" wire:navigate>
                    {{ __('Agregar categoría') }}
                </flux:button>
            </div>

            @if ($tournament->globalCategories->isEmpty())
                <x-ui.empty-state icon="rectangle-group" :message="__('Este torneo todavía no tiene categorías. Elegilas de tu catálogo.')">
                    <x-slot:action>
                        <flux:button :href="route('tournaments.global-categories.create', $tournament)" variant="primary" size="sm" icon="plus" wire:navigate>
                            {{ __('Agregar categoría') }}
                        </flux:button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <div class="flex flex-wrap justify-center gap-4">
                    @foreach ($tournament->globalCategories as $category)
                        @php $categoryLocked = $lockedCategoryIds->contains($category->id); @endphp

                        <div class="hover-lift group relative w-full rounded-3xl border border-zinc-200 bg-white p-7 dark:border-white/10 glass-panel sm:w-[calc(50%-0.5rem)] lg:w-[calc(33.333%-0.667rem)] lg:max-w-sm">
                            {{-- "Stretched link": covers the whole card so clicking anywhere on
                                 it navigates to the category, while the real buttons below sit
                                 above it (relative + z-10) to intercept their own clicks first --
                                 this keeps everything visually inside one card without nesting a
                                 <button> inside an <a> (invalid HTML, and unreliable to click). --}}
                            <a href="{{ route('categories.show', $category) }}" wire:navigate class="absolute inset-0 z-0 rounded-3xl" aria-label="{{ $category->name }}"></a>

                            <div class="relative flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-cyan-500/15 text-cyan-400">
                                        <flux:icon icon="rectangle-group" variant="micro" class="size-5" />
                                    </div>

                                    <flux:heading size="lg" class="truncate text-xl!">{{ $category->name }}</flux:heading>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                                    @if ($categoryLocked)
                                        <flux:badge size="sm" color="zinc" icon="lock-closed">{{ __('Fase iniciada') }}</flux:badge>
                                    @endif
                                    <flux:badge size="sm" :color="$category->status->color()">{{ $category->status->label() }}</flux:badge>
                                </div>
                            </div>

                            <div class="relative mt-5 text-sm text-zinc-500 dark:text-white/60">
                                {{ trans_choice(':count plantel inscrito|:count planteles inscritos', $globalTeamCounts[$category->id] ?? 0, ['count' => $globalTeamCounts[$category->id] ?? 0]) }}
                            </div>

                            <div class="relative z-10 mt-5 flex items-center justify-between gap-2">
                                <flux:button :href="route('tournaments.global-categories.teams.edit', [$tournament, $category])" variant="ghost" size="sm" wire:navigate>
                                    {{ $categoryLocked ? __('Ver planteles') : __('Elegir planteles') }}
                                </flux:button>

                                @unless ($categoryLocked)
                                    <x-ui.confirm-delete-form
                                        :action="route('tournaments.global-categories.destroy', [$tournament, $category])"
                                        :heading="__('¿Quitar :category de este torneo?', ['category' => $category->name])"
                                        :description="__('También se quitan los planteles que hayas elegido para ella en este torneo.')"
                                    >
                                        <flux:button variant="ghost" size="sm" icon="x-mark" />
                                    </x-ui.confirm-delete-form>
                                @endunless
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
