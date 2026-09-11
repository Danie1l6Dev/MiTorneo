<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-950">
        {{-- El x-init arma la transicion del borde izquierdo del fondo un frame
             despues del montaje: en ese primer frame Flux ya aplico el estado
             retraido del sidebar que guarda en localStorage, asi que ese salto
             queda sin animar y solo se anima lo que el usuario dispara despues. --}}
        <div
            class="stadium-bg"
            aria-hidden="true"
            x-data
            x-init="requestAnimationFrame(() => $el.classList.add('stadium-bg-animated'))"
            style="--stadium-photo: url('{{ asset('assets/images/stadium-background.png') }}')"
        ></div>

        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-zinc-900 glass-panel-strong">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                {{-- No se usa el :heading de <flux:sidebar.group> porque ese componente
                     se oculta entero (in-data-flux-sidebar-collapsed-desktop:hidden) al
                     retraer el sidebar en desktop, y con el desaparecerian tambien los
                     items. Aqui el titulo va aparte y es lo unico que se oculta: los
                     items quedan como una columna de iconos. --}}
                <div class="px-3 py-2 in-data-flux-sidebar-collapsed-desktop:hidden">
                    <div class="text-sm leading-none font-medium text-zinc-400">{{ __('Plataforma') }}</div>
                </div>

                <div class="flex flex-col">
                    @if (auth()->user()->role === \App\Enums\UserRole::Admin)
                        <flux:sidebar.item icon="shield-check" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                            {{ __('Dashboard administrativo') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                            {{ __('Usuarios') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="trophy" :href="route('admin.tournaments.index')" :current="request()->routeIs('admin.tournaments.*')" wire:navigate>
                            {{ __('Torneos') }}
                        </flux:sidebar.item>
                    @else
                        <flux:sidebar.item icon="trophy" :href="route('dashboard')" :current="request()->routeIs('dashboard', 'tournaments.*', 'categories.*', 'phases.*', 'groups.*', 'teams.*', 'matches.*')" wire:navigate>
                            {{ __('Mis torneos') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="flag" :href="route('referees.index')" :current="request()->routeIs('referees.*')" wire:navigate>
                            {{ __('Árbitros') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="shield-exclamation" :href="route('sanctions.index')" :current="request()->routeIs('sanctions.*')" wire:navigate>
                            {{ __('Sanciones') }}
                        </flux:sidebar.item>
                    @endif
                </div>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden border-b border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-zinc-900 glass-panel-strong">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
