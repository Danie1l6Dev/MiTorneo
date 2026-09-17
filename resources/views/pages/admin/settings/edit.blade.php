<x-layouts::app :title="__('Configuración')">
    <div class="mx-auto w-full max-w-2xl space-y-6 animate-fade-in-up">
        <x-ui.page-header :title="__('Configuración')" :subtitle="__('Funciones que un administrador puede prender o apagar sin necesidad de un cambio de código.')" />

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
        @endif

        <div class="space-y-4 rounded-2xl border border-zinc-200 p-5 dark:border-white/10 glass-panel">
            <div class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <flux:heading size="lg">{{ __('Carga de PDF de resolución en sanciones') }}</flux:heading>

                    <flux:text class="text-sm">
                        {{ __('Permite adjuntar el PDF de la resolución del comité deportivo al resolver una roja directa o al expulsar un plantel, en vez de escribir un motivo en texto. Mientras esté apagada, esas pantallas siguen pidiendo el motivo en texto como hasta ahora.') }}
                    </flux:text>

                    <flux:text class="text-xs text-zinc-500 dark:text-white/50">
                        {{ __('El servidor actual tiene espacio limitado -- dejala apagada hasta migrar a un servidor con más capacidad.') }}
                    </flux:text>
                </div>

                <flux:badge size="sm" :color="$setting->sanction_pdf_uploads_enabled ? 'green' : 'zinc'">
                    {{ $setting->sanction_pdf_uploads_enabled ? __('Habilitada') : __('Deshabilitada') }}
                </flux:badge>
            </div>

            <form method="POST" action="{{ route('admin.settings.toggle-sanction-pdf-uploads') }}">
                @csrf
                @method('PATCH')
                <flux:button type="submit" variant="primary">
                    {{ $setting->sanction_pdf_uploads_enabled ? __('Deshabilitar') : __('Habilitar') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::app>
