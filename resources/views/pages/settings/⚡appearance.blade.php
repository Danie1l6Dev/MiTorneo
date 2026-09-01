<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Apariencia')" :subheading="__('MiTorneo usa siempre el tema oscuro, pensado para integrarse con el fondo de estadio.')">
        <div class="flex items-center gap-3 rounded-2xl border border-zinc-200 p-4 dark:border-white/10 glass-panel">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent-content/15 text-accent-content">
                <flux:icon.moon variant="micro" class="size-5" />
            </div>

            <div>
                <div class="text-sm font-semibold text-zinc-800 dark:text-white">{{ __('Oscuro') }}</div>
                <flux:text class="text-sm">{{ __('Modo fijo de la plataforma. No hay versión clara disponible.') }}</flux:text>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
