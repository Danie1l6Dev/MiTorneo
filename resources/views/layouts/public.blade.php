<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-950">
        <div class="stadium-bg" aria-hidden="true" style="--stadium-photo: url('{{ asset('assets/images/stadium-background.png') }}')"></div>

        {{-- No sidebar, no dashboard link, no user menu -- this layout is the
             only one reachable without logging in, so it must never assume
             an authenticated user exists. --}}
        <header class="sticky top-0 z-20 border-b border-zinc-200 bg-zinc-50/95 backdrop-blur dark:border-white/10 dark:bg-zinc-900/95">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <x-app-logo />
                <flux:badge size="sm" color="zinc" icon="eye">{{ __('Portal público') }}</flux:badge>
            </div>
        </header>

        <main class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6 sm:py-10">
            {{ $slot }}
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
