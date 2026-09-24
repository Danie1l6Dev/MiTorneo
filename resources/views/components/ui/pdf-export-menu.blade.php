@props([
    'label' => __('Exportar PDF'),
])

{{--
    Dropdown version of x-ui.pdf-export-button, for when there's more than
    one thing to export from the same spot (a jornada, the whole phase, the
    whole category...). Same fetch-then-save approach, for the same reason
    documented there: the loading state follows the real response instead
    of a guessed timeout. Each item in the slot calls
    download(href, filename) -- Alpine resolves it from this x-data since
    the menu stays inside it in the DOM, and an item can just as well read
    state from an OUTER x-data (e.g. the calendar's current jornada).
--}}
<div
    x-data="{
        exporting: false,
        async download(href, filename) {
            this.exporting = true

            try {
                const response = await fetch(href)

                if (! response.ok) {
                    throw new Error('export failed')
                }

                const blob = await response.blob()
                const url = URL.createObjectURL(blob)

                const link = document.createElement('a')
                link.href = url
                link.download = filename
                link.click()

                URL.revokeObjectURL(url)
            } catch (error) {
                alert('{{ __('No se pudo generar el PDF. Intenta de nuevo.') }}')
            } finally {
                this.exporting = false
            }
        },
    }"
    class="inline-flex"
>
    <flux:dropdown position="bottom" align="end">
        <flux:button :loading="true" x-bind:data-loading="exporting" icon="arrow-down-tray" icon:trailing="chevron-down" {{ $attributes }}>
            {{ $label }}
        </flux:button>

        <flux:menu>
            {{ $slot }}
        </flux:menu>
    </flux:dropdown>
</div>
