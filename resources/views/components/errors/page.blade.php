{{--
    Página de error de marca (404, 403, 419, 429, 500, 503...), en vez de la
    página genérica de Laravel/Symfony. Usa el mismo layout que login/registro
    (x-layouts::auth.simple): fondo de estadio, logo, tarjeta centrada -- y
    nunca asume un usuario autenticado, porque un error puede pasarle a
    cualquiera, con o sin sesión.
--}}
@props([
    'code',
    'title',
    'description',
    'icon' => 'question-mark-circle',
    'tone' => 'zinc', // 'zinc' para errores 4xx, 'red' para errores 5xx
])

@php
    $toneClasses = match ($tone) {
        'red' => 'bg-red-50 text-red-500 dark:bg-red-500/10 dark:text-red-400',
        default => 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-white/60',
    };
@endphp

<x-layouts::auth.simple :title="$code.' - '.$title">
    <div class="flex flex-col items-center gap-6 text-center">
        <div class="relative flex items-center justify-center">
            <span class="text-8xl font-bold tracking-tight text-zinc-100 select-none dark:text-white/[0.06]" aria-hidden="true">
                {{ $code }}
            </span>
            <div class="absolute flex size-14 items-center justify-center rounded-full {{ $toneClasses }}">
                <flux:icon :icon="$icon" variant="outline" class="size-7" />
            </div>
        </div>

        <div class="flex flex-col gap-1">
            <flux:heading size="lg" level="1">{{ $title }}</flux:heading>
            <flux:subheading>{{ $description }}</flux:subheading>
        </div>

        <div class="flex w-full flex-col gap-2 sm:flex-row sm:justify-center">
            <flux:button variant="primary" :href="route('home')" class="w-full sm:w-auto" wire:navigate>
                {{ __('Ir al inicio') }}
            </flux:button>
            <flux:button variant="ghost" onclick="history.back()" class="w-full sm:w-auto">
                {{ __('Volver atrás') }}
            </flux:button>
        </div>
    </div>
</x-layouts::auth.simple>
