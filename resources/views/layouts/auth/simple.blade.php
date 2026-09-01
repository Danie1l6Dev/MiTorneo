<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-950">
        <div class="stadium-bg" aria-hidden="true" style="--stadium-photo: url('{{ asset('assets/images/stadium-background.png') }}')"></div>

        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col items-center gap-3 animate-fade-in-up">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="flex h-12 w-12 mb-1 items-center justify-center rounded-2xl bg-accent-content text-accent-foreground shadow-lg shadow-black/30">
                        <x-app-logo-icon class="size-6 fill-current" />
                    </span>
                    <span class="text-sm font-semibold uppercase tracking-widest text-zinc-500 dark:text-white/70">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <div class="flex w-full flex-col gap-6 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-white/10 dark:bg-white/5 sm:p-8 glass-panel">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
