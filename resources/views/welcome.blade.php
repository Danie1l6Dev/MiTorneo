<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-950">
        <div class="stadium-bg" aria-hidden="true"></div>

        <div class="flex min-h-svh flex-col">
            <header class="flex items-center justify-between gap-4 p-6 lg:px-10">
                <div class="flex items-center gap-2.5">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-accent-content text-accent-foreground shadow-lg shadow-black/30">
                        <x-app-logo-icon class="size-5 fill-current" />
                    </span>
                    <span class="text-sm font-semibold uppercase tracking-widest text-white/80">{{ config('app.name') }}</span>
                </div>

                <div class="flex items-center gap-2">
                    @auth
                        <flux:button :href="route('dashboard')" variant="primary" wire:navigate>{{ __('Ir al dashboard') }}</flux:button>
                    @else
                        <flux:button :href="route('login')" variant="ghost" wire:navigate>{{ __('Iniciar sesión') }}</flux:button>
                        <flux:button :href="route('register')" variant="primary" wire:navigate>{{ __('Crear cuenta') }}</flux:button>
                    @endauth
                </div>
            </header>

            <main class="flex flex-1 flex-col items-center justify-center gap-8 px-6 py-16 text-center">
                <div class="animate-fade-in-up space-y-5">
                    <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-xs font-semibold uppercase tracking-widest text-white/70 glass-panel">
                        <flux:icon.bolt variant="micro" class="size-3.5 text-accent-content" />
                        {{ __('Gestión de torneos de fútbol') }}
                    </div>

                    <h1 class="text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">
                        {{ __('Organiza tu torneo como') }}
                        <span class="text-accent-content">{{ __('un profesional') }}</span>
                    </h1>

                    <p class="mx-auto max-w-xl text-base text-white/70 sm:text-lg">
                        {{ __('Categorías, grupos, calendarios, resultados y tablas de posiciones, todo en un solo lugar.') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    @auth
                        <flux:button :href="route('dashboard')" variant="primary" icon:trailing="arrow-right" wire:navigate class="h-12! px-6! text-base!">
                            {{ __('Ir al dashboard') }}
                        </flux:button>
                    @else
                        <flux:button :href="route('register')" variant="primary" icon:trailing="arrow-right" wire:navigate class="h-12! px-6! text-base!">
                            {{ __('Crear una cuenta gratis') }}
                        </flux:button>
                        <flux:button :href="route('login')" variant="ghost" wire:navigate class="h-12! px-6! text-base!">
                            {{ __('Ya tengo cuenta') }}
                        </flux:button>
                    @endauth
                </div>
            </main>
        </div>

        @fluxScripts
    </body>
</html>
